<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Recovers orders whose order.created webhook never arrived (app down, deploy, store error):
 * fetches each order through the REST API and runs it through the same handler as the webhook.
 * Safe to repeat — orders already in order_log are reported as duplicates.
 */
#[Signature('woo:pull-order {ids* : One or more WooCommerce order IDs}')]
#[Description('Fetch WooCommerce orders by ID and process them like an order.created webhook')]
class WooPullOrder extends Command
{
    public function handle(OrderCreatedHandler $handler): int
    {
        try {
            $woo = app(WooCommerceClient::class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;
        foreach ($this->argument('ids') as $id) {
            try {
                $result = $handler->handle($woo->get('orders/'.(int) $id));
                $this->components->twoColumnDetail("Order #{$id}", "{$result['status']} (order_log {$result['order_log_id']})");
            } catch (WooCommerceException $e) {
                $failed = true;
                $this->components->twoColumnDetail("Order #{$id}", '<fg=red>'.$e->getMessage().'</>');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
