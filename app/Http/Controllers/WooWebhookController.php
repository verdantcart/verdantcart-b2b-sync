<?php

namespace App\Http\Controllers;

use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
use App\Services\WooCommerce\Webhooks\ProductUpdatedHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives signed WooCommerce webhooks (see VerifyWooWebhookSignature) and routes them by topic.
 *
 * WooCommerce disables a webhook after 5 consecutive failed deliveries, so once a delivery
 * is safely stored we always answer 2xx; unknown topics get 202 and are ignored.
 */
class WooWebhookController extends Controller
{
    public function __invoke(Request $request, OrderCreatedHandler $orderCreated, ProductUpdatedHandler $productUpdated): JsonResponse
    {
        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $payload = $request->json()->all();

        if ($payload === [] || ! isset($payload['id'])) {
            // Usually WooCommerce could not build the resource and sent a WP REST error instead
            // (e.g. {"code":"rest_no_route"}). Log enough to see why, but no personal data.
            // Missed orders can be recovered with `php artisan woo:pull-order {id}`.
            Log::warning('WooCommerce webhook without a resource id', [
                'topic' => $topic,
                'delivery_id' => $request->header('X-WC-Webhook-Delivery-ID'),
                'keys' => array_slice(array_keys($payload), 0, 20),
                'code' => $payload['code'] ?? null,
                'message' => isset($payload['message']) ? mb_strimwidth((string) $payload['message'], 0, 300) : null,
                'body_bytes' => strlen($request->getContent()),
            ]);

            return response()->json(['error' => 'Expected a JSON resource with an id'], 422);
        }

        return match ($topic) {
            'order.created' => response()->json($orderCreated->handle($payload)),
            'product.created', 'product.updated' => $this->productResponse($productUpdated->handle($payload)),
            default => $this->ignored($topic, $payload),
        };
    }

    private function ignored(string $topic, array $payload): JsonResponse
    {
        Log::info('Ignoring WooCommerce webhook topic', ['topic' => $topic, 'resource_id' => $payload['id']]);

        return response()->json(['status' => 'ignored', 'topic' => $topic], 202);
    }

    /** Ignored products (no SKU, variable parents) are acknowledged with 202, like unknown topics. */
    private function productResponse(array $result): JsonResponse
    {
        return response()->json($result, $result['status'] === 'ignored' ? 202 : 200);
    }
}
