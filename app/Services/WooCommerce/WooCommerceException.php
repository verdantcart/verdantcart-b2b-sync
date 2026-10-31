<?php

namespace App\Services\WooCommerce;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A failed WooCommerce REST call. Carries the HTTP status and Woo's own error code
 * (e.g. "woocommerce_rest_authentication_error") so callers can decide whether to retry.
 */
class WooCommerceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $wooCode = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response, string $method, string $endpoint): self
    {
        $body = $response->json();
        $code = is_array($body) ? ($body['code'] ?? null) : null;
        $detail = is_array($body) && isset($body['message'])
            ? strip_tags((string) $body['message'])
            : mb_strimwidth(trim(strip_tags($response->body())), 0, 200, '…');

        return new self(
            sprintf('WooCommerce %s %s failed with HTTP %d%s: %s',
                $method, $endpoint, $response->status(), $code ? " ({$code})" : '', $detail ?: 'no details'),
            $response->status(),
            $code,
            is_array($body) ? $body : null,
        );
    }

    /** 429 and 5xx are worth retrying later; 4xx (bad data, auth) are not. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
