<?php

use App\Http\Controllers\WooWebhookController;
use App\Http\Middleware\VerifyWooWebhookSignature;
use Illuminate\Support\Facades\Route;

// POST /webhooks/woocommerce — one endpoint for all Woo topics, routed by X-WC-Webhook-Topic.
Route::post('woocommerce', WooWebhookController::class)
    ->middleware(VerifyWooWebhookSignature::class)
    ->name('woocommerce');
