<?php

namespace App\Console\Commands;

use App\Erp\ErpAdapter;
use App\Services\Catalogue\CatalogueImporter;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('woo:pull-products')]
#[Description('Import every WooCommerce product and variation, and link them to Fortnox articles')]
class WooPullProducts extends Command
{
    public function handle(CatalogueImporter $importer, WooCommerceClient $woo, ErpAdapter $erp): int
    {
        try {
            $result = $importer->run($woo, $erp, fn (string $step) => $this->line("  {$step}"));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$result['imported']} products imported ({$result['skipped']} without SKU skipped). "
            ."{$result['linked']} newly linked to {$erp->name()} articles, {$result['unlinked']} not linked.");

        if ($result['unlinked_list'] !== []) {
            $this->table(['SKU', 'Name', 'Suggested article'], array_map(
                fn (array $p) => [$p['sku'], $p['name'], $p['suggestion'] ?? ''], $result['unlinked_list']));
            $this->line('  Link them on the setup page (/setup), step "Bring in your products".');
        }

        return self::SUCCESS;
    }
}
