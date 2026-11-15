<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FortnoxConnectionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SyncJobController;
use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Support\Facades\Route;

// Admin only, like everything else here. The public demo opens it with B2B_PUBLIC_DASHBOARD=true.
Route::get('/', DashboardController::class)->middleware(HorizonBasicAuth::class.':dashboard')->name('dashboard');

// Fortnox OAuth: an admin logs in to Fortnox once; tokens are stored encrypted in erp_connections.
// Same admin protection as the Horizon dashboard (open locally, password elsewhere).
Route::middleware(HorizonBasicAuth::class)->prefix('fortnox')->name('fortnox.')->group(function () {
    Route::get('connect', [FortnoxConnectionController::class, 'connect'])->name('connect');
    Route::get('callback', [FortnoxConnectionController::class, 'callback'])->name('callback');
});

// Failed / dead sync jobs with a retry button (admin, same protection as Horizon).
Route::middleware(HorizonBasicAuth::class)->group(function () {
    Route::get('sync-jobs', [SyncJobController::class, 'index'])->name('sync-jobs.index');
    Route::post('sync-jobs/{syncJob}/retry', [SyncJobController::class, 'retry'])->name('sync-jobs.retry');
});

// Setup guide: connect WooCommerce and Fortnox, choose the stock buffer, create the plugin token.
Route::middleware(HorizonBasicAuth::class)->prefix('setup')->name('setup')->controller(SetupController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('woo/check', 'checkWoo')->name('.woo.check');
    Route::post('woo/webhooks', 'registerWebhooks')->name('.woo.webhooks');
    Route::post('fortnox/check', 'checkFortnox')->name('.fortnox.check');
    Route::post('buffer', 'saveBuffer')->name('.buffer');
    Route::post('token', 'createToken')->name('.token');
});
