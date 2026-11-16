<?php

namespace App\Jobs;

use App\Erp\ErpAdapter;
use App\Services\Customers\ErpCustomerImporter;
use App\Services\Setup\ImportStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Setup guide: import the customers that already exist in Fortnox. */
class ImportErpCustomers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ErpCustomerImporter $importer, ErpAdapter $erp): void
    {
        ImportStatus::running(ImportStatus::CUSTOMERS, 'Reading customers from Fortnox');

        try {
            $result = $importer->run($erp);
        } catch (Throwable $e) {
            Log::error('Customer import failed', ['error' => $e->getMessage()]);
            ImportStatus::failed(ImportStatus::CUSTOMERS, $e->getMessage());

            return;
        }

        ImportStatus::done(ImportStatus::CUSTOMERS, $result);
    }
}
