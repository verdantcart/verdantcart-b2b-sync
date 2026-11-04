<?php

namespace App\Erp\Fortnox;

use App\Erp\ErpAdapter;
use App\Erp\ErpCustomer;
use App\Erp\ErpException;
use App\Models\OrderLog;
use Illuminate\Support\Carbon;

class FortnoxAdapter implements ErpAdapter
{
    public function __construct(private readonly FortnoxClient $client) {}

    public function name(): string
    {
        return 'fortnox';
    }

    /**
     * POST /3/orders. Prices are sent as the final B2B unit price (ex. VAT, no row discount),
     * so Fortnox's totals match ours to the öre; the tier is noted in the order remarks.
     */
    public function pushOrder(OrderLog $order): string
    {
        $customerNumber = $order->customer?->fortnox_customer_number;
        if (! $customerNumber) {
            throw new ErpException('Order customer is not linked to a Fortnox customer number. Run `php artisan erp:pull-customers` or set it on the customer.');
        }

        $rows = collect($order->priced_lines ?? [])->map(function (array $line) {
            if (empty($line['fortnox_article_number'])) {
                throw new ErpException("SKU {$line['sku']} has no Fortnox article number.");
            }

            return [
                'ArticleNumber' => $line['fortnox_article_number'],
                'Description' => mb_substr((string) $line['name'], 0, 50),
                'OrderedQuantity' => (string) $line['quantity'],
                'DeliveredQuantity' => (string) $line['quantity'],
                'Price' => $line['unit_price'],
            ];
        })->values()->all();

        if ($rows === []) {
            throw new ErpException('Order has no priced lines.');
        }

        $shipping = $order->woo_payload['shipping'] ?? [];
        $tiers = collect($order->priced_lines)->pluck('tier')->filter()->unique()->implode(', ');

        $response = $this->client->post('orders', ['Order' => array_filter([
            'CustomerNumber' => $customerNumber,
            'OrderDate' => Carbon::parse($order->received_at ?? now())->timezone('Europe/Stockholm')->toDateString(),
            'Currency' => $order->currency,
            'VATIncluded' => false,
            'YourOrderNumber' => (string) $order->woo_order_id,
            'ExternalInvoiceReference1' => 'Woo #'.$order->woo_order_id,
            'Remarks' => 'Imported by VerdantCart B2B Sync'.($tiers ? ". Price tier: {$tiers}" : ''),
            'DeliveryName' => trim(($shipping['company'] ?? '') ?: trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? ''))) ?: null,
            'DeliveryAddress1' => $shipping['address_1'] ?? null,
            'DeliveryZipCode' => $shipping['postcode'] ?? null,
            'DeliveryCity' => $shipping['city'] ?? null,
            'OrderRows' => $rows,
        ], fn ($v) => $v !== null && $v !== '')]);

        $documentNumber = $response['Order']['DocumentNumber'] ?? null;
        if (! $documentNumber) {
            throw new ErpException('Fortnox accepted the order but returned no document number.');
        }

        return (string) $documentNumber;
    }

    /** GET /3/customers, all pages. */
    public function pullCustomers(): iterable
    {
        $page = 1;
        do {
            $response = $this->client->get('customers', ['limit' => 500, 'page' => $page]);
            foreach ($response['Customers'] ?? [] as $c) {
                yield new ErpCustomer(
                    customerNumber: (string) $c['CustomerNumber'],
                    name: (string) ($c['Name'] ?? ''),
                    organisationNumber: $c['OrganisationNumber'] ?? null ?: null,
                    email: $c['Email'] ?? null ?: null,
                    vatNumber: $c['VATNumber'] ?? null ?: null,
                    countryCode: $c['CountryCode'] ?? null ?: null,
                    currency: $c['Currency'] ?? null ?: null,
                    active: (bool) ($c['Active'] ?? true),
                );
            }
            $totalPages = (int) ($response['MetaInformation']['@TotalPages'] ?? 1);
        } while ($page++ < $totalPages);
    }

    /**
     * GET /3/articles/{n}. Uses DisposableQuantity (stock minus what Fortnox orders already
     * reserve): orders we have pushed are reserved there, so we must not count them again.
     */
    public function stockLevels(array $articleNumbers): array
    {
        $levels = [];
        foreach (array_unique(array_filter($articleNumbers)) as $number) {
            $article = $this->client->get('articles/'.rawurlencode($number))['Article'] ?? [];
            if (! ($article['StockGoods'] ?? false)) {
                continue; // not stock-managed in Fortnox
            }
            $levels[$number] = (int) floor((float) ($article['DisposableQuantity'] ?? $article['QuantityInStock'] ?? 0));
        }

        return $levels;
    }
}
