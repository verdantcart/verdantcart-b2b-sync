<?php

namespace App\Providers;

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeMailNotificationsTo('ops@verdantcart.ai'); // Day 12: long-wait alerts
    }

    /**
     * Access is decided by HorizonBasicAuth (see config/horizon.php 'middleware').
     * This second check means the dashboard stays closed even if that middleware is removed.
     */
    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request) => app()->environment('local')
            || $request->attributes->get(HorizonBasicAuth::VERIFIED) === true);
    }
}
