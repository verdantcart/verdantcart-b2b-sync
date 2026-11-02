<?php

use App\Http\Controllers\Api\CustomerPriceController;
use App\Http\Controllers\Api\CustomerTierController;
use App\Http\Controllers\Api\TierController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/*
| Tier management API. Every call needs a Sanctum token: `Authorization: Bearer <token>`.
| Create one with `php artisan api:token "Name"`. Token abilities:
|   tiers:read, tiers:write, customers:write, prices:read   (or * for all)
*/
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('tiers', [TierController::class, 'index'])
        ->middleware(CheckForAnyAbility::class.':tiers:read,tiers:write')->name('api.tiers.index');

    Route::post('tiers', [TierController::class, 'store'])
        ->middleware(CheckForAnyAbility::class.':tiers:write')->name('api.tiers.store');

    Route::post('customers/{customer}/tier', [CustomerTierController::class, 'store'])
        ->whereNumber('customer')
        ->middleware(CheckForAnyAbility::class.':customers:write')->name('api.customers.tier');

    Route::get('customers/{customer}/price/{sku}', [CustomerPriceController::class, 'show'])
        ->whereNumber('customer')
        ->middleware(CheckForAnyAbility::class.':prices:read')->name('api.customers.price');
});
