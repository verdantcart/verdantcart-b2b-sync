<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TierRequest;
use App\Models\B2bPriceTier;
use App\Models\ProductSku;
use App\Services\Pricing\PriceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** Price tiers: list, create, edit, delete. Admin only (same login as Horizon). */
class TierController extends Controller
{
    public function index(): View
    {
        return view('tiers.index', [
            'tiers' => B2bPriceTier::query()->withCount('customers')->orderByDesc('priority')->orderBy('name')->get(),
            'example' => ProductSku::query()->where('is_active', true)->orderBy('sku')->first(),
            'prices' => app(PriceResolver::class),
        ]);
    }

    public function store(TierRequest $request): RedirectResponse
    {
        $tier = B2bPriceTier::create($request->validated());

        return redirect()->route('tiers.index')->with('status', "Tier {$tier->name} created. Add customers to it from their customer page.");
    }

    public function edit(B2bPriceTier $tier, PriceResolver $prices): View
    {
        $skus = ProductSku::query()->where('is_active', true)->orderBy('sku')->limit(8)->get();

        return view('tiers.edit', [
            'tier' => $tier->loadCount('customers'),
            'customers' => $tier->customers()->orderBy('company_name')->limit(50)->get(),
            'quotes' => $skus->map(fn (ProductSku $sku) => $prices->quoteForTier($sku, $tier)),
        ]);
    }

    public function update(TierRequest $request, B2bPriceTier $tier): RedirectResponse
    {
        $tier->update($request->validated());

        return redirect()->route('tiers.edit', $tier)->with('status', 'Saved. New orders use the new discount; the shop shows it within '
            .config('b2bsync.storefront.cache_ttl').' seconds.');
    }

    public function destroy(B2bPriceTier $tier): RedirectResponse
    {
        if ($tier->customers()->exists()) {
            return back()->with('error', "{$tier->name} still has customers. Move them to another tier first, or untick Active to stop using it.");
        }

        $tier->delete();

        return redirect()->route('tiers.index')->with('status', "Tier {$tier->name} deleted.");
    }
}
