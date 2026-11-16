<?php

namespace App\Jobs;

use App\Erp\ErpAdapter;
use App\Services\Catalogue\CatalogueImporter;
use App\Services\Setup\ImportStatus;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Setup guide: import the WooCommerce catalogue and link it to Fortnox articles. */
class ImportCatalogue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CatalogueImporter $importer, WooCommerceClient $woo, ErpAdapter $erp): void
    {
        ImportStatus::running(ImportStatus::PRODUCTS, 'Reading products from WooCommerce');

        try {
            $result = $importer->run($woo, $erp, fn (string $step) => ImportStatus::running(ImportStatus::PRODUCTS, $step));
        } catch (Throwable $e) {
            Log::error('Catalogue import failed', ['error' => $e->getMessage()]);
            ImportStatus::failed(ImportStatus::PRODUCTS, $e->getMessage());

            return;
        }

        ImportStatus::done(ImportStatus::PRODUCTS, $result);
    }
}
