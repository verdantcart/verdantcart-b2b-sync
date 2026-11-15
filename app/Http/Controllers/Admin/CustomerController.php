<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerRequest;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * B2B customers: who they are, which WooCommerce and Fortnox customer they are linked to,
 * and which price tiers they belong to. Admin only (same login as Horizon).
 */
class CustomerController extends Controller
{
    /** Filters for the list, as shown in the filter menu. */
    public const FILTERS = [
        'no-tier' => 'No price tier',
        'no-woo' => 'Not linked to WooCommerce',
        'no-fortnox' => 'Not in Fortnox',
        'inactive' => 'Inactive',
    ];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $filter = (string) $request->query('filter');
        $tierSlug = (string) $request->query('tier');

        $customers = B2bCustomer::query()
            ->with('priceTiers')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw('lower(company_name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('lower(email) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('fortnox_customer_number', $search)
                // Org. numbers match from the start, with or without the dash: "556" or "5560000000".
                ->when(preg_match('/^[0-9-]{3,}$/', $search), fn (Builder $q) => $q->orWhereRaw("replace(org_number, '-', '') like ?", [str_replace('-', '', $search).'%']))
                ->when(ctype_digit($search), fn (Builder $q) => $q->orWhere('woo_customer_id', (int) $search))))
            ->when($filter === 'no-tier', fn (Builder $q) => $q->doesntHave('priceTiers'))
            ->when($filter === 'no-woo', fn (Builder $q) => $q->whereNull('woo_customer_id'))
            ->when($filter === 'no-fortnox', fn (Builder $q) => $q->whereNull('fortnox_customer_number'))
            ->when($filter === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($tierSlug !== '', fn (Builder $q) => $q->whereHas('priceTiers', fn (Builder $q) => $q->where('slug', $tierSlug)))
            ->orderBy('company_name')
            ->paginate(25)
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'tiers' => B2bPriceTier::query()->orderByDesc('priority')->get(['id', 'name', 'slug']),
            'filters' => self::FILTERS,
            'search' => $search,
            'filter' => $filter,
            'tierSlug' => $tierSlug,
            'now' => now(),
        ]);
    }

    public function create(): View
    {
        return view('customers.create', ['customer' => new B2bCustomer(['country_code' => 'SE', 'currency' => 'SEK', 'is_active' => true])]);
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = B2bCustomer::create($request->validated());

        return redirect()->route('customers.show', $customer)->with('status', "{$customer->company_name} added. Now choose a price tier below.");
    }

    public function show(B2bCustomer $customer, PriceResolver $prices): View
    {
        $customer->load(['priceTiers' => fn ($q) => $q->orderByDesc('priority')]);
        $skus = ProductSku::query()->where('is_active', true)->orderBy('sku')->limit(8)->get();

        return view('customers.show', [
            'customer' => $customer,
            'effective' => $customer->is_active ? PriceResolver::pickTier($customer->priceTiers, now()) : null,
            'availableTiers' => B2bPriceTier::query()->whereNotIn('id', $customer->priceTiers->modelKeys())->orderByDesc('priority')->get(),
            'quotes' => $prices->quoteMany($customer, $skus),
            'orders' => $customer->orders()->latest('received_at')->limit(5)->get(),
            'now' => now(),
        ]);
    }

    public function update(CustomerRequest $request, B2bCustomer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()->route('customers.show', $customer)->with('status', 'Saved.');
    }

    public function assignTier(Request $request, B2bCustomer $customer): RedirectResponse
    {
        $data = Validator::make($request->all(), [
            'tier_id' => ['required', 'integer', 'exists:b2b_price_tiers,id'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after:valid_from', 'after:today'],
        ], [
            'valid_until.after' => 'The end date must be after the start date and in the future.',
        ])->validateWithBag('tier');

        // Dates are days in the shop's time zone. An end date means "up to and including that day".
        $tz = config('app.display_timezone');
        $tier = B2bPriceTier::findOrFail($data['tier_id']);
        $customer->priceTiers()->syncWithoutDetaching([$tier->id => [
            'valid_from' => isset($data['valid_from']) ? Carbon::parse($data['valid_from'], $tz)->startOfDay()->utc() : null,
            'valid_until' => isset($data['valid_until']) ? Carbon::parse($data['valid_until'], $tz)->addDay()->startOfDay()->utc() : null,
        ]]);

        return redirect()->route('customers.show', $customer)->with('status', "{$customer->company_name} is now in {$tier->name}.");
    }

    public function removeTier(B2bCustomer $customer, B2bPriceTier $tier): RedirectResponse
    {
        $customer->priceTiers()->detach($tier->id);

        return redirect()->route('customers.show', $customer)->with('status', "Removed from {$tier->name}.");
    }

    /**
     * Look the customer up in WooCommerce by e-mail and offer the match.
     * Short timeout and no retries: someone is waiting on the page.
     */
    public function findInWoo(B2bCustomer $customer): RedirectResponse
    {
        try {
            $matches = app(WooCommerceClient::class)->forInteractiveUse()
                ->get('customers', ['email' => $customer->email, 'role' => 'all', 'per_page' => 5]);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not search WooCommerce: '.$e->getMessage());
        }

        if ($matches === []) {
            return back()->with('error', "No WooCommerce customer has the e-mail {$customer->email}. Check the address, or enter the customer ID by hand.");
        }

        $match = $matches[0];
        $taken = B2bCustomer::where('woo_customer_id', $match['id'])->whereKeyNot($customer->id)->first();
        if ($taken) {
            return back()->with('error', "WooCommerce customer {$match['id']} is already linked to {$taken->company_name}.");
        }

        return back()->withInput(['woo_customer_id' => $match['id']])
            ->with('status', "Found WooCommerce customer {$match['id']} (".trim(($match['first_name'] ?? '').' '.($match['last_name'] ?? '')).'). Press Save to link it.');
    }
}
