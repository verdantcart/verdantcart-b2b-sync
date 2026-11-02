<?php

namespace App\Providers;

use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // 120 API calls per minute per token (or per IP before authentication).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->currentAccessToken()?->id ?? $request->ip()));

        Relation::enforceMorphMap([
            'order_log' => OrderLog::class,
            'product_sku' => ProductSku::class,
            'user' => User::class, // owner of Sanctum API tokens
        ]);
    }
}
