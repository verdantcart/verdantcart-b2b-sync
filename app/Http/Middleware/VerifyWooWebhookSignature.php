<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook calls that were not signed by our WooCommerce store.
 *
 * WooCommerce sends X-WC-Webhook-Signature = base64(HMAC-SHA256(raw body, webhook secret)).
 * The one unsigned request it makes is the "ping" when a webhook is created
 * (body "webhook_id=123"); that is let through and answered without side effects.
 */
class VerifyWooWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isPing($request)) {
            return response()->json(['status' => 'pong']);
        }

        $secret = (string) config('services.woocommerce.webhook_secret');
        if ($secret === '') {
            Log::critical('WooCommerce webhook received but WOO_WEBHOOK_SECRET is not set.');

            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }

        $given = (string) $request->header('X-WC-Webhook-Signature');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($given === '' || ! hash_equals($expected, $given)) {
            Log::warning('Rejected WooCommerce webhook with invalid signature', [
                'topic' => $request->header('X-WC-Webhook-Topic'),
                'source' => $request->header('X-WC-Webhook-Source'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }

    public static function isPing(Request $request): bool
    {
        return ! $request->hasHeader('X-WC-Webhook-Topic')
            && preg_match('/^webhook_id=\d+$/', trim($request->getContent())) === 1;
    }
}
