<?php

namespace App\Services\Customers;

use App\Erp\ErpAdapter;
use App\Erp\ErpCustomer;
use App\Erp\ErpException;
use App\Models\B2bCustomer;

/**
 * ERP → app customers. Links ERP customers to B2B customers by customer number, then
 * organisation number, then e-mail; customers not yet known are created (no tier).
 * Used by `php artisan erp:pull-customers` and the setup guide.
 */
class ErpCustomerImporter
{
    /**
     * @return array{created: int, linked: int, updated: int, skipped: list<string>}
     *
     * @throws ErpException
     */
    public function run(ErpAdapter $erp, bool $dryRun = false): array
    {
        $counts = ['created' => 0, 'linked' => 0, 'updated' => 0, 'skipped' => []];

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
                    $counts['skipped'][] = "{$remote->customerNumber} {$remote->name}";

                    continue;
                }
                $dryRun || B2bCustomer::create($attributes + ['is_active' => $remote->active]);
                $counts['created']++;

                continue;
            }

            $customer->fill($attributes);
            if ($customer->isDirty()) {
                $dryRun || $customer->save();
                $counts[$how === 'number' ? 'updated' : 'linked']++;
            }
        }

        return $counts;
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
