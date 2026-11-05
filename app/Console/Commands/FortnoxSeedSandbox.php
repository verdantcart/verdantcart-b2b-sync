<?php

namespace App\Console\Commands;

use App\Erp\ErpException;
use App\Erp\Fortnox\FortnoxClient;
use App\Models\B2bCustomer;
use App\Models\ProductSku;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Copies the customers and products that exist in the WooCommerce store into a Fortnox
 * test company, using the numbers the app already stores (fortnox_customer_number,
 * fortnox_article_number), so orders from the shop can be pushed straight away.
 *
 * Only for sandboxes: it shows the connected company and asks before writing anything.
 * Safe to re-run: records that already exist in Fortnox are left as they are.
 */
#[Signature('fortnox:seed-sandbox {--force : Do not ask for confirmation}')]
#[Description('Create the demo customers and articles in the connected Fortnox test company')]
class FortnoxSeedSandbox extends Command
{
    public function handle(FortnoxClient $fortnox, WooCommerceClient $woo): int
    {
        try {
            $company = $fortnox->get('companyinformation')['CompanyInformation'] ?? [];
        } catch (ErpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $name = ($company['CompanyName'] ?? '?').' ('.($company['OrganizationNumber'] ?? '?').')';
        // Only what really exists in the WooCommerce store, not every demo row in the local database.
        try {
            $wooSkus = $woo->paginate('products')->pluck('sku')->filter()->all();
            $wooEmails = $woo->paginate('customers', ['role' => 'all'])->pluck('email')->filter()->map(fn ($e) => strtolower($e))->all();
        } catch (WooCommerceException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $customers = B2bCustomer::whereNotNull('fortnox_customer_number')->orderBy('id')->get()
            ->filter(fn (B2bCustomer $c) => in_array(strtolower($c->email), $wooEmails, true))->values();
        $skus = ProductSku::whereIn('sku', $wooSkus)->whereNotNull('fortnox_article_number')->orderBy('id')->get();

        $this->components->info("Connected Fortnox company: {$name}");
        $this->line("  Will create up to {$customers->count()} customers and {$skus->count()} articles (existing ones are skipped).");

        if (! $this->option('force') && ! $this->confirm("Is {$name} a TEST company you want demo data in?")) {
            return self::FAILURE;
        }

        $this->components->info('Customers');
        $this->table(['Number', 'Name', ''], $this->seed($fortnox, $customers, 'customers', 'Customer',
            fn (B2bCustomer $c) => $c->fortnox_customer_number,
            fn (B2bCustomer $c) => array_filter([
                'CustomerNumber' => $c->fortnox_customer_number,
                'Name' => $c->company_name,
                'OrganisationNumber' => $c->org_number,
                'Email' => $c->email,
                'CountryCode' => $c->country_code,
                'Currency' => $c->currency,
                'Type' => 'COMPANY',
            ]),
            fn (B2bCustomer $c) => $c->company_name));

        $this->components->info('Articles');
        $this->table(['Number', 'Description', ''], $this->seed($fortnox, $skus, 'articles', 'Article',
            fn (ProductSku $s) => $s->fortnox_article_number,
            fn (ProductSku $s) => [
                'ArticleNumber' => $s->fortnox_article_number,
                'Description' => mb_substr($s->name, 0, 200),
                'StockGoods' => true,
                'QuantityInStock' => (string) ($s->inventory?->warehouse_quantity ?? 0),
            ],
            fn (ProductSku $s) => $s->name));

        $this->components->info('Done. Place an order in the shop to send it to Fortnox.');

        return self::SUCCESS;
    }

    /** @return list<array{string, string, string}> table rows */
    private function seed(FortnoxClient $fortnox, Collection $items, string $endpoint, string $wrapper, callable $number, callable $payload, callable $label): array
    {
        return $items->map(function ($item) use ($fortnox, $endpoint, $wrapper, $number, $payload, $label) {
            $n = (string) $number($item);
            try {
                if ($this->exists($fortnox, "{$endpoint}/".rawurlencode($n))) {
                    return [$n, $label($item), 'exists'];
                }
                $fortnox->post($endpoint, [$wrapper => $payload($item)]);

                return [$n, $label($item), '<fg=green>created</>'];
            } catch (ErpException $e) {
                return [$n, $label($item), '<fg=red>'.mb_strimwidth($e->getMessage(), 0, 120, '…').'</>'];
            }
        })->all();
    }

    private function exists(FortnoxClient $fortnox, string $path): bool
    {
        try {
            $fortnox->get($path);

            return true;
        } catch (ErpException $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
    }
}
