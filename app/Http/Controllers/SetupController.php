<?php

namespace App\Http\Controllers;

use App\Jobs\ImportCatalogue;
use App\Jobs\ImportErpCustomers;
use App\Models\AppSetting;
use App\Models\InventoryCache;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\Inventory\InventorySyncRequester;
use App\Services\Setup\ImportStatus;
use App\Services\Setup\SetupGuide;
use App\Services\WooCommerce\WebhookRegistrar;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\StockBuffer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The setup guide at /setup: everything a new installation needs, as buttons instead of
 * terminal commands. Admin only (same login as Horizon).
 */
class SetupController extends Controller
{
    public function __construct(private readonly SetupGuide $guide) {}

    public function index(): View
    {
        $steps = $this->guide->steps();

        return view('setup', [
            'steps' => $steps,
            'done' => collect($steps)->where('state', SetupGuide::DONE)->count(),
        ]);
    }

    public function checkWoo(): RedirectResponse
    {
        $result = $this->guide->checkWoo();

        return $result['ok']
            ? $this->back('woo', 'status', "WooCommerce answered: {$result['products']} products, currency ".($result['currency'] ?? '?').'.')
            : $this->back('woo', 'error', $result['error']);
    }

    public function registerWebhooks(): RedirectResponse
    {
        $secret = (string) config('services.woocommerce.webhook_secret');
        if ($secret === '') {
            return $this->back('webhooks', 'error', 'WOO_WEBHOOK_SECRET is empty. customer:provision sets it; locally, run php artisan woo:webhooks once and it creates one.');
        }

        try {
            // WooCommerce pings the delivery URL when a webhook is created. With a single-process
            // `php artisan serve` that ping waits for this request, so allow more than 30 seconds.
            set_time_limit(120);
            $rows = (new WebhookRegistrar(SetupGuide::interactiveWoo()))->register(WebhookRegistrar::defaultUrl(), $secret);
        } catch (WooCommerceException|InvalidArgumentException $e) {
            return $this->back('webhooks', 'error', $e->getMessage());
        }

        $this->guide->markWebhooksRegistered();
        $created = collect($rows)->where('action', 'created')->count();

        return $this->back('webhooks', 'status', count($rows).' webhooks active ('.$created.' new). Place a test order to see it arrive.');
    }

    public function checkFortnox(): RedirectResponse
    {
        $result = $this->guide->checkFortnox();

        if ($result['error'] !== null) {
            return $this->back('fortnox', 'error', $result['error']);
        }
        if ($result['missing'] !== []) {
            return $this->back('fortnox', 'error', 'This Fortnox company is missing: '.implode('; ', $result['missing']).'.');
        }

        return $this->back('fortnox', 'status', 'Fortnox works for '.($result['company'] ?? 'this company').'. Orders, customers and articles are all allowed.');
    }

    public function saveBuffer(Request $request, InventorySyncRequester $requester): RedirectResponse
    {
        $data = $request->validate([
            'buffer' => ['required', 'integer', 'min:0', 'max:'.StockBuffer::MAX],
            'apply_to_all' => ['sometimes', 'boolean'],
        ]);
        $buffer = (int) $data['buffer'];

        AppSetting::write(StockBuffer::SETTING, $buffer);

        if (! $request->boolean('apply_to_all')) {
            return $this->back('buffer', 'status', "New products get a buffer of {$buffer}. Products that already have one keep it.");
        }

        InventoryCache::query()->update(['buffer_quantity' => $buffer]);
        $queued = 0;
        ProductSku::query()->whereHas('inventory')->where('is_active', true)
            ->chunkById(200, function ($skus) use ($requester, &$queued) {
                foreach ($skus as $sku) {
                    $requester->request($sku, ['source' => 'setup:buffer']);
                    $queued++;
                }
            });

        return $this->back('buffer', 'status', "Buffer set to {$buffer} for every product. {$queued} stock updates queued for WooCommerce.");
    }

    public function importProducts(): RedirectResponse
    {
        if (ImportStatus::isBusy(ImportStatus::get(ImportStatus::PRODUCTS))) {
            return $this->back('products', 'status', 'The import is already running.');
        }
        ImportStatus::queued(ImportStatus::PRODUCTS);
        ImportCatalogue::dispatch();

        return $this->back('products', 'status', 'Import started. This page updates by itself.');
    }

    /** Link products to Fortnox articles by hand, for the ones the import could not match. */
    public function linkProducts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'articles' => ['required', 'array'],
            'articles.*' => ['nullable', 'string', 'max:50'],
        ]);
        $numbers = collect($data['articles'])->map(fn ($n) => trim((string) $n))->filter();

        $duplicates = $numbers->duplicates();
        if ($duplicates->isNotEmpty()) {
            return $this->back('products', 'error', 'The same Fortnox article can only be linked to one product: '.$duplicates->unique()->implode(', ').'.');
        }
        $taken = ProductSku::whereIn('fortnox_article_number', $numbers->values())->whereNotIn('id', $numbers->keys())->pluck('sku', 'fortnox_article_number');
        if ($taken->isNotEmpty()) {
            return $this->back('products', 'error', 'Already linked: '.$taken->map(fn ($sku, $number) => "{$number} → {$sku}")->implode(', ').'.');
        }

        $linked = 0;
        foreach ($numbers as $id => $number) {
            $sku = ProductSku::whereKey($id)->whereNull('fortnox_article_number')->first();
            if ($sku) {
                $sku->update(['fortnox_article_number' => $number]);
                app(InventorySyncRequester::class)->request($sku, ['source' => 'setup:link']);
                $linked++;
            }
        }

        return $this->back('products', 'status', "{$linked} ".Str::plural('product', $linked).' linked. Their stock now follows Fortnox.');
    }

    public function importCustomers(): RedirectResponse
    {
        if (ImportStatus::isBusy(ImportStatus::get(ImportStatus::CUSTOMERS))) {
            return $this->back('customers', 'status', 'The import is already running.');
        }
        ImportStatus::queued(ImportStatus::CUSTOMERS);
        ImportErpCustomers::dispatch();

        return $this->back('customers', 'status', 'Import started. This page updates by itself.');
    }

    public function createToken(): RedirectResponse
    {
        $token = User::apiService()->createToken(SetupGuide::STOREFRONT_TOKEN_NAME, [SetupGuide::STOREFRONT_ABILITY]);

        // Flashed for exactly one page view; only the hash is stored.
        return redirect()->to(route('setup').'#token')->with('new_token', $token->plainTextToken);
    }

    private function back(string $step, string $type, string $message): RedirectResponse
    {
        return redirect()->to(route('setup').'#'.$step)->with($type, $message)->with('step', $step);
    }
}
