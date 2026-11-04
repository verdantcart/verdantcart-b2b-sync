<?php

namespace App\Console\Commands;

use App\Erp\ErpAdapter;
use App\Erp\ErpCustomer;
use App\Erp\ErpException;
use App\Models\B2bCustomer;
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
    public function handle(ErpAdapter $erp): int
    {
        $counts = ['linked' => 0, 'updated' => 0, 'created' => 0];

        try {
            foreach ($erp->pullCustomers() as $remote) {
                [$customer, $how] = $this->match($remote);
                $attributes = array_filter([
                    'fortnox_customer_number' => $remote->customerNumber,
                    'company_name' => $remote->name ?: null,
                    'org_number' => $remote->organisationNumber,
                    'email' => $remote->email ? strtolower($remote->email) : null,
                    'vat_number' => $remote->vatNumber,
                    'country_code' => $remote->countryCode ? strtoupper($remote->countryCode) : null,
                    'currency' => $remote->currency,
                ], fn ($v) => $v !== null && $v !== '');

                if ($customer === null) {
                    if (! isset($attributes['email'])) {
                        $this->components->warn("Skipped ERP customer {$remote->customerNumber} ({$remote->name}): no e-mail.");

                        continue;
                    }
                    $this->option('dry-run') || B2bCustomer::create($attributes + ['is_active' => $remote->active]);
                    $counts['created']++;

                    continue;
                }

                $customer->fill($attributes);
                if ($customer->isDirty()) {
                    $this->option('dry-run') || $customer->save();
                    $counts[$how === 'number' ? 'updated' : 'linked']++;
                }
            }
        } catch (ErpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(($this->option('dry-run') ? '[dry run] ' : '')
            ."{$counts['created']} created, {$counts['linked']} newly linked, {$counts['updated']} updated from {$erp->name()}.");

        return self::SUCCESS;
    }

    /** @return array{0: ?B2bCustomer, 1: string} */
    private function match(ErpCustomer $remote): array
    {
        if ($c = B2bCustomer::where('fortnox_customer_number', $remote->customerNumber)->first()) {
            return [$c, 'number'];
        }
        if ($remote->organisationNumber && $c = B2bCustomer::whereNull('fortnox_customer_number')
            ->whereRaw("replace(org_number, '-', '') = ?", [str_replace('-', '', $remote->organisationNumber)])->first()) {
            return [$c, 'org_number'];
        }
        if ($remote->email && $c = B2bCustomer::whereNull('fortnox_customer_number')->whereRaw('lower(email) = ?', [strtolower($remote->email)])->first()) {
            return [$c, 'email'];
        }

        return [null, 'none'];
    }
}
