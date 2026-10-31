<?php

namespace App\Http\Controllers;

use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
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
    public function __invoke(Request $request, OrderCreatedHandler $orderCreated): JsonResponse
    {
        $topic = (string) $request->header('X-WC-Webhook-Topic');
        $payload = $request->json()->all();

        if ($payload === [] || ! isset($payload['id'])) {
            return response()->json(['error' => 'Expected a JSON resource with an id'], 422);
        }

        return match ($topic) {
            'order.created' => response()->json($orderCreated->handle($payload)),
            default => $this->ignored($topic, $payload),
        };
    }

    private function ignored(string $topic, array $payload): JsonResponse
    {
        Log::info('Ignoring WooCommerce webhook topic', ['topic' => $topic, 'resource_id' => $payload['id']]);

        return response()->json(['status' => 'ignored', 'topic' => $topic], 202);
    }
}
