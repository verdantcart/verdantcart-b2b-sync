<?php

use VerdantCart\B2bPrices\Plugin;

/**
 * Plugin Name:       VerdantCart B2B Prices
 * Description:       Shows each wholesale customer their own price in WooCommerce, using the same price rules VerdantCart B2B Sync uses for Fortnox orders.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            VerdantCart
 * License:           MIT
 * Text Domain:       verdantcart-b2b-prices
 */
defined('ABSPATH') || exit;

const VCB2B_VERSION = '0.1.0';

require_once __DIR__.'/includes/ApiClient.php';
require_once __DIR__.'/includes/PriceCache.php';
require_once __DIR__.'/includes/Settings.php';
require_once __DIR__.'/includes/Plugin.php';

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

/**
 * The B2B unit price ("162.00") a WooCommerce customer pays for a SKU, or null when the
 * shop should keep its own price (not a B2B customer, unknown SKU, or B2B Sync unreachable).
 */
function vcb2b_customer_price(int $customerId, string $sku): ?string
{
    return Plugin::instance()->cache()->unitPrice($customerId, $sku);
}
