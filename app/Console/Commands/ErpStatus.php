<?php

namespace App\Console\Commands;

use App\Erp\ErpAdapter;
use App\Erp\Fortnox\FortnoxClient;
use App\Erp\Fortnox\LicenceCheck;
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

        $result = app(LicenceCheck::class)->run();
        if ($result['error'] !== null) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Company', ($result['company'] ?? '?').' ('.($result['organisation_number'] ?? '?').')');
        $this->components->info('Fortnox API is reachable and the login works.');

        foreach ($result['checks'] as $check) {
            if ($check['ok']) {
                $this->components->twoColumnDetail($check['label'], '<fg=green>OK</>');
            } elseif ($check['error'] !== null) {
                $this->components->twoColumnDetail($check['label'], '<fg=red>error</>');
                $this->components->error($check['error']);

                return self::FAILURE;
            } else {
                $this->components->twoColumnDetail($check['label'], "<fg=red>missing licence: {$check['licence']}</>");
            }
        }

        if ($result['missing'] !== []) {
            $this->components->error('This Fortnox company cannot be used with B2B Sync until it has: '.implode('; ', $result['missing']).'.');

            return self::FAILURE;
        }

        app(ErpAdapter::class); // resolves the binding: fails loudly on a bad ERP_DRIVER

        return self::SUCCESS;
    }
}
