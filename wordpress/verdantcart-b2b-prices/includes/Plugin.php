<?php

namespace VerdantCart\B2bPrices;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    private ?PriceCache $cache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function boot(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }

        if (is_admin()) {
            Settings::register();
        }

        Storefront::make($this->cache())->register();

        // A customer who just logged in should see their current prices, not an hour-old copy.
        add_action('wp_login', function (string $login, \WP_User $user): void {
            $this->cache()->forget($user->ID);
        }, 10, 2);
    }

    public function cache(): PriceCache
    {
        return $this->cache ??= new PriceCache(ApiClient::fromSettings());
    }

    public static function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'verdantcart-b2b-prices']);

            return;
        }
        error_log('[verdantcart-b2b-prices] '.$message);
    }
}
