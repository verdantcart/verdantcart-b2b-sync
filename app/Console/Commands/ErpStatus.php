<?php

namespace App\Console\Commands;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
use App\Erp\Fortnox\FortnoxClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:status')]
#[Description('Show which ERP is configured and test the connection')]
class ErpStatus extends Command
{
    public function handle(FortnoxClient $fortnox): int
    {
        $driver = (string) config('erp.driver');
        $this->components->twoColumnDetail('ERP driver', $driver);

        if ($driver !== 'fortnox') {
            $this->components->info('Using the fake ERP: orders get FAKE-… document numbers, no external calls.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Client ID set', $fortnox->isConfigured() ? 'yes' : '<fg=red>no</>');
        $connection = $fortnox->connection();
        $this->components->twoColumnDetail('Connected', $connection ? 'since '.$connection->connected_at->toDateTimeString() : '<fg=red>no – open /fortnox/connect</>');

        if (! $connection) {
            return self::FAILURE;
        }

        try {
            $company = $fortnox->get('companyinformation')['CompanyInformation'] ?? [];
            $this->components->twoColumnDetail('Company', ($company['CompanyName'] ?? '?').' ('.($company['OrganizationNumber'] ?? '?').')');
            $this->components->info('Fortnox API is reachable and the login works.');
        } catch (ErpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        app(ErpAdapter::class); // resolves the binding: fails loudly on a bad ERP_DRIVER

        return self::SUCCESS;
    }
}
