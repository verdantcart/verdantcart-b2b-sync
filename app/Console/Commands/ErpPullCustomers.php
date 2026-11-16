<?php

namespace App\Console\Commands;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
use App\Services\Customers\ErpCustomerImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ERP → app customer sync. Links ERP customers to B2B customers by customer number,
 * then organisation number, then e-mail; customers not yet known are created (no tier).
 */
#[Signature('erp:pull-customers {--dry-run : Show what would change without saving}')]
#[Description('Import and link customers from the ERP (Fortnox) into b2b_customers')]
class ErpPullCustomers extends Command
{
    public function handle(ErpAdapter $erp, ErpCustomerImporter $importer): int
    {
        try {
            $counts = $importer->run($erp, (bool) $this->option('dry-run'));
        } catch (ErpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($counts['skipped'] as $skipped) {
            $this->components->warn("Skipped ERP customer {$skipped}: no e-mail.");
        }

        $this->components->info(($this->option('dry-run') ? '[dry run] ' : '')
            ."{$counts['created']} created, {$counts['linked']} newly linked, {$counts['updated']} updated from {$erp->name()}.");

        return self::SUCCESS;
    }
}
