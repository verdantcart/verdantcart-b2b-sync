<?php

namespace App\Erp;

use App\Models\B2bCustomer;
use App\Models\OrderLog;
use Illuminate\Support\Facades\Log;

/**
 * Stand-in ERP for local development and demos (ERP_DRIVER=fake).
 * Accepts every order and returns a predictable document number; it has no stock data,
 * so warehouse figures stay whatever `php artisan inventory:set` put there.
 */
class FakeErpAdapter implements ErpAdapter
{
    public function name(): string
    {
        return 'fake';
    }

    public function pushOrder(OrderLog $order): string
    {
        Log::info('Fake ERP accepted order', ['woo_order_id' => $order->woo_order_id, 'lines' => count($order->priced_lines ?? [])]);

        return 'FAKE-'.$order->woo_order_id;
    }

    public function pullCustomers(): iterable
    {
        return B2bCustomer::whereNotNull('fortnox_customer_number')->get()
            ->map(fn (B2bCustomer $c) => new ErpCustomer(
                $c->fortnox_customer_number, $c->company_name, $c->org_number, $c->email, $c->vat_number, $c->country_code, $c->currency, $c->is_active,
            ));
    }

    public function stockLevels(array $articleNumbers): array
    {
        return [];
    }
}
