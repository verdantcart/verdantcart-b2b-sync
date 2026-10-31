<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('woo:ping')]
#[Description('Check the WooCommerce REST API connection and show basic store counts')]
class WooPing extends Command
{
    public function handle(): int
    {
        try {
            $woo = app(WooCommerceClient::class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connecting to {$woo->baseUrl()}");
        $this->components->twoColumnDetail('Authentication', $woo->usesOAuth() ? 'OAuth 1.0a (http)' : 'Basic auth (https)');

        try {
            $this->components->twoColumnDetail('Products', (string) $woo->total('products'));
            $this->components->twoColumnDetail('Customers', (string) $woo->total('customers', ['role' => 'all']));
            $this->components->twoColumnDetail('Orders', (string) $woo->total('orders'));
            $currency = collect($woo->get('settings/general'))->firstWhere('id', 'woocommerce_currency');
            $this->components->twoColumnDetail('Store currency', $currency['value'] ?? '?');
        } catch (WooCommerceException $e) {
            $this->components->error($e->getMessage());
            if ($e->status === 401) {
                $this->line('  Check WOO_CONSUMER_KEY / WOO_CONSUMER_SECRET, and that the key has Read/Write access.');
            }

            return self::FAILURE;
        }

        $this->components->info('WooCommerce API is reachable and the key works.');

        return self::SUCCESS;
    }
}
