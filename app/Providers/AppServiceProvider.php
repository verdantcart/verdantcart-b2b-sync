<?php

namespace App\Providers;

use App\Models\OrderLog;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
