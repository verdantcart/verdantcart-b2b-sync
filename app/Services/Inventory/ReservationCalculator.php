<?php

namespace App\Services\Inventory;

use App\Models\OrderLog;
use App\Models\ProductSku;

/**
 * Stock that is sold but not yet booked in the ERP: quantities on orders we have received
 * or priced but not synced. Once an order reaches the ERP (Day 11) its quantities come off
 * the ERP's warehouse figure instead, so it stops counting here.
 */
class ReservationCalculator
{
    public const OPEN_STATUSES = [OrderLog::STATUS_RECEIVED, OrderLog::STATUS_PRICED];

    public function reservedFor(ProductSku $sku): int
    {
        $reserved = 0;

        OrderLog::whereIn('status', self::OPEN_STATUSES)->select(['id', 'status', 'woo_payload', 'priced_lines'])
            ->lazyById(200)
            ->each(function (OrderLog $order) use ($sku, &$reserved) {
                $lines = $order->status === OrderLog::STATUS_PRICED
                    ? ($order->priced_lines ?? [])
                    : ($order->woo_payload['line_items'] ?? []);

                foreach ($lines as $line) {
                    if ($this->matches($line, $sku)) {
                        $reserved += (int) ($line['quantity'] ?? 0);
                    }
                }
            });

        return $reserved;
    }

    private function matches(array $line, ProductSku $sku): bool
    {
        if (($line['sku'] ?? '') === $sku->sku) {
            return true;
        }

        // Woo line items carry product/variation ids even if the SKU text was changed.
        return $sku->woo_product_id !== null
            && (int) ($line['product_id'] ?? 0) === (int) $sku->woo_product_id
            && (int) ($line['variation_id'] ?? 0) === (int) ($sku->woo_variation_id ?? 0);
    }
}
