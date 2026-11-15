<?php

namespace App\Erp\Fortnox;

use App\Erp\ErpException;

/**
 * Asks Fortnox for the company name and one record of each resource B2B Sync uses.
 * Fortnox answers 2001101 when the company lacks the licence for a resource.
 * Used by `php artisan erp:status` and by the setup guide.
 */
class LicenceCheck
{
    /** Fortnox resource => [what B2B Sync uses it for, the licence the company needs]. */
    public const CHECKS = [
        'orders' => ['Orders', 'Offert & Order'],
        'customers' => ['Customers', 'Offert & Order or Fakturering'],
        'articles' => ['Articles and stock', 'Offert & Order or Fakturering (stock levels also need Lager)'],
    ];

    public function __construct(private readonly FortnoxClient $fortnox) {}

    /**
     * @return array{
     *     company: ?string,
     *     organisation_number: ?string,
     *     error: ?string,
     *     checks: array<string, array{label: string, ok: bool, licence: string, error: ?string}>,
     *     missing: list<string>,
     * }
     */
    public function run(): array
    {
        $result = ['company' => null, 'organisation_number' => null, 'error' => null, 'checks' => [], 'missing' => []];

        try {
            $company = $this->fortnox->get('companyinformation')['CompanyInformation'] ?? [];
            $result['company'] = $company['CompanyName'] ?? null;
            $result['organisation_number'] = $company['OrganizationNumber'] ?? null;
        } catch (ErpException $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        foreach (self::CHECKS as $path => [$label, $licence]) {
            $check = ['label' => $label, 'ok' => true, 'licence' => $licence, 'error' => null];

            try {
                $this->fortnox->get($path, ['limit' => 1]);
            } catch (ErpException $e) {
                $check['ok'] = false;
                if ($e->errorCode === FortnoxClient::NO_LICENCE) {
                    $result['missing'][] = $licence;
                } else {
                    $check['error'] = $e->getMessage();
                }
            }

            $result['checks'][$path] = $check;
        }

        $result['missing'] = array_values(array_unique($result['missing']));

        return $result;
    }

    /** True when the login works and every resource answered. */
    public static function passed(array $result): bool
    {
        return $result['error'] === null
            && $result['checks'] !== []
            && collect($result['checks'])->every(fn (array $c) => $c['ok']);
    }
}
