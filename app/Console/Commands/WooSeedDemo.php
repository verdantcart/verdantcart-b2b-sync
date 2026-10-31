<?php

namespace App\Console\Commands;

use App\Models\B2bCustomer;
use App\Models\ProductSku;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pushes part of the local demo catalogue and B2B customers into a (test) WooCommerce
 * store and stores the real Woo IDs locally, so later webhooks can be matched.
 * Safe to re-run: items that already exist in Woo (same SKU / e-mail) are linked, not duplicated.
 */
#[Signature('woo:seed-demo {--products=10 : Number of local SKUs to create in Woo} {--customers=5 : Number of local B2B customers to create in Woo}')]
#[Description('Create demo products and B2B customers in the WooCommerce test store and link their IDs')]
class WooSeedDemo extends Command
{
    public function handle(): int
    {
        try {
            $woo = app(WooCommerceClient::class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! str_contains($woo->baseUrl(), '.test') && ! str_contains($woo->baseUrl(), 'localhost')
            && ! $this->confirm("{$woo->baseUrl()} does not look like a local test store. Create demo data there anyway?")) {
            return self::FAILURE;
        }

        try {
            $this->seedProducts($woo, (int) $this->option('products'));
            $this->seedCustomers($woo, (int) $this->option('customers'));
        } catch (WooCommerceException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function seedProducts(WooCommerceClient $woo, int $limit): void
    {
        if ($limit < 1) {
            return;
        }

        $skus = ProductSku::with('inventory')->where('is_active', true)->orderBy('id')->limit($limit)->get();
        $existing = collect($woo->get('products', ['sku' => $skus->pluck('sku')->implode(','), 'per_page' => 100]))
            ->keyBy('sku');

        $toCreate = $skus->reject(fn (ProductSku $s) => $existing->has($s->sku));
        $created = $this->batchCreate($woo, 'products/batch', $toCreate, fn (ProductSku $s) => [
            'name' => $s->name,
            'type' => 'simple',
            'status' => 'publish',
            'sku' => $s->sku,
            'regular_price' => (string) $s->base_price,
            'manage_stock' => true,
            'stock_quantity' => $s->inventory?->available_quantity ?? 0,
        ]);

        $rows = $skus->map(function (ProductSku $sku) use ($existing, $created) {
            $wooId = $existing[$sku->sku]['id'] ?? $created[$sku->sku] ?? null;
            if (is_int($wooId)) {
                $sku->update(['woo_product_id' => $wooId, 'woo_variation_id' => null]);
            }

            return [$sku->sku, $sku->name, $wooId ?? 'failed', $existing->has($sku->sku) ? 'linked' : 'created'];
        });

        $this->components->info('Products');
        $this->table(['SKU', 'Name', 'Woo ID', ''], $rows);
    }

    private function seedCustomers(WooCommerceClient $woo, int $limit): void
    {
        if ($limit < 1) {
            return;
        }

        $customers = B2bCustomer::where('is_active', true)->whereNotNull('fortnox_customer_number')
            ->orderBy('id')->limit($limit)->get();

        $existing = $customers->mapWithKeys(fn (B2bCustomer $c) => [
            $c->email => $woo->get('customers', ['email' => $c->email, 'role' => 'all'])[0]['id'] ?? null,
        ])->filter();

        $toCreate = $customers->reject(fn (B2bCustomer $c) => $existing->has($c->email));
        $created = $this->batchCreate($woo, 'customers/batch', $toCreate, fn (B2bCustomer $c) => [
            'email' => $c->email,
            'username' => $c->email,
            'first_name' => 'Inköp',
            'last_name' => $c->company_name,
            'billing' => [
                'company' => $c->company_name,
                'email' => $c->email,
                'country' => $c->country_code,
            ],
            'meta_data' => [
                ['key' => 'vc_org_number', 'value' => $c->org_number],
                ['key' => 'vc_vat_number', 'value' => $c->vat_number],
            ],
        ], key: 'email');

        $rows = $customers->map(function (B2bCustomer $customer) use ($existing, $created) {
            $wooId = $existing[$customer->email] ?? $created[$customer->email] ?? null;
            if (is_int($wooId)) {
                $customer->update(['woo_customer_id' => $wooId]);
            }

            return [$customer->company_name, $customer->email, $wooId ?? 'failed',
                $existing->has($customer->email) ? 'linked' : 'created'];
        });

        $this->components->info('B2B customers');
        $this->table(['Company', 'E-mail', 'Woo ID', ''], $rows);
    }

    /**
     * Create items through a Woo batch endpoint (max 100 per call).
     *
     * @return array<string, int|string> Map of item key (SKU / e-mail) => Woo ID, or error message.
     */
    private function batchCreate(WooCommerceClient $woo, string $endpoint, Collection $items, callable $payload, string $key = 'sku'): array
    {
        $result = [];
        foreach ($items->chunk(100) as $chunk) {
            $response = $woo->post($endpoint, ['create' => $chunk->map($payload)->values()->all()]);
            foreach ($chunk->values() as $i => $item) {
                $created = $response['create'][$i] ?? [];
                $result[$item->{$key}] = ! empty($created['id'])
                    ? (int) $created['id']
                    : 'failed: '.($created['error']['message'] ?? 'unknown error');
            }
        }

        return $result;
    }
}
