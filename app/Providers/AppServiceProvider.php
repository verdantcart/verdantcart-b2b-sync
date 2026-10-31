<?php

namespace App\Providers;

use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WooCommerceClient::class, fn ($app) => new WooCommerceClient(
            http: $app->make(HttpFactory::class),
            baseUrl: (string) config('services.woocommerce.base_url'),
            consumerKey: (string) config('services.woocommerce.consumer_key'),
            consumerSecret: (string) config('services.woocommerce.consumer_secret'),
            apiVersion: (string) config('services.woocommerce.api_version', 'wc/v3'),
            timeout: (int) config('services.woocommerce.timeout', 15),
            verifySsl: (bool) config('services.woocommerce.verify_ssl', true),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Store short, stable names in sync_jobs.syncable_type instead of PHP class names.
        Relation::enforceMorphMap([
            'order_log' => OrderLog::class,
            'product_sku' => ProductSku::class,
        ]);
    }
}
