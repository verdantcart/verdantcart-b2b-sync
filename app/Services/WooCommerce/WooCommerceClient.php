<?php

namespace App\Services\WooCommerce;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Thin wrapper around the WooCommerce REST API (wc/v3).
 *
 * Authentication follows WooCommerce's own rules:
 *  - https:// stores: HTTP Basic auth with consumer key/secret.
 *  - http://  stores: WooCommerce rejects Basic auth, so every request is signed
 *    with one-legged OAuth 1.0a (HMAC-SHA256), as in WC_REST_Authentication.
 */
class WooCommerceClient
{
    private const RETRY_DELAYS_MS = [250, 1000];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $apiVersion = 'wc/v3',
        private readonly int $timeout = 15,
        private readonly bool $verifySsl = true,
    ) {
        if ($baseUrl === '' || $consumerKey === '' || $consumerSecret === '') {
            throw new InvalidArgumentException(
                'WooCommerce is not configured. Set WOO_BASE_URL, WOO_CONSUMER_KEY and WOO_CONSUMER_SECRET in .env.'
            );
        }
    }

    public function get(string $endpoint, array $query = []): array
    {
        return $this->send('GET', $endpoint, $query)->json() ?? [];
    }

    public function post(string $endpoint, array $data = [], array $query = []): array
    {
        return $this->send('POST', $endpoint, $query, $data)->json() ?? [];
    }

    public function put(string $endpoint, array $data = [], array $query = []): array
    {
        return $this->send('PUT', $endpoint, $query, $data)->json() ?? [];
    }

    public function delete(string $endpoint, array $query = []): array
    {
        return $this->send('DELETE', $endpoint, $query)->json() ?? [];
    }

    /** Total number of items in a collection, from WooCommerce's X-WP-Total header. */
    public function total(string $endpoint, array $query = []): int
    {
        return (int) $this->send('GET', $endpoint, ['per_page' => 1] + $query)->header('X-WP-Total');
    }

    /**
     * Lazily walk every page of a collection (products, orders, customers...).
     *
     * @return LazyCollection<int, array>
     */
    public function paginate(string $endpoint, array $query = [], int $perPage = 100): LazyCollection
    {
        return LazyCollection::make(function () use ($endpoint, $query, $perPage) {
            $page = 1;
            do {
                $response = $this->send('GET', $endpoint, ['per_page' => $perPage, 'page' => $page] + $query);
                foreach ($response->json() ?? [] as $item) {
                    yield $item;
                }
                $totalPages = (int) ($response->header('X-WP-TotalPages') ?: 1);
            } while ($page++ < $totalPages);
        });
    }

    public function usesOAuth(): bool
    {
        return ! str_starts_with(strtolower($this->baseUrl), 'https://');
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /**
     * Send a request and return the response, or throw WooCommerceException.
     * Connection errors, 429 and 5xx are retried twice with a short backoff.
     * Each attempt is signed again, because WooCommerce rejects a reused OAuth nonce.
     */
    public function send(string $method, string $endpoint, array $query = [], ?array $data = null): Response
    {
        $method = strtoupper($method);
        $url = $this->url($endpoint);
        $query = self::normalizeQuery($query);

        $attempt = 0;
        while (true) {
            try {
                $response = $this->dispatch($method, $url, $query, $data);
            } catch (ConnectionException $e) {
                if ($attempt < count(self::RETRY_DELAYS_MS)) {
                    Sleep::usleep(self::RETRY_DELAYS_MS[$attempt++] * 1000);

                    continue;
                }
                throw new WooCommerceException("Could not reach WooCommerce at {$this->baseUrl()}: {$e->getMessage()}");
            }

            $retryable = $response->status() === 429 || $response->serverError();
            if ($retryable && $attempt < count(self::RETRY_DELAYS_MS)) {
                Sleep::usleep(self::RETRY_DELAYS_MS[$attempt++] * 1000);

                continue;
            }

            if ($response->failed()) {
                throw WooCommerceException::fromResponse($response, $method, $endpoint);
            }

            return $response;
        }
    }

    private function dispatch(string $method, string $url, array $query, ?array $data): Response
    {
        $request = $this->http->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->withOptions(['verify' => $this->verifySsl])
            ->withUserAgent('VerdantCart-B2B-Sync/0.1');

        if ($this->usesOAuth()) {
            $query = $this->signOAuth($method, $url, $query);
        } else {
            $request = $request->withBasicAuth($this->consumerKey, $this->consumerSecret);
        }

        $fullUrl = $query ? $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;

        return match ($method) {
            'GET' => $request->get($fullUrl),
            'DELETE' => $request->delete($fullUrl),
            'POST' => $request->post($fullUrl, $data ?? []),
            'PUT' => $request->put($fullUrl, $data ?? []),
            default => throw new InvalidArgumentException("Unsupported HTTP method {$method}"),
        };
    }

    private function url(string $endpoint): string
    {
        return $this->baseUrl().'/wp-json/'.trim($this->apiVersion, '/').'/'.ltrim($endpoint, '/');
    }

    /**
     * Add one-legged OAuth 1.0a parameters, mirroring WC_REST_Authentication::check_oauth_signature().
     */
    public function signOAuth(string $method, string $url, array $query): array
    {
        $params = $query + [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => Str::random(32),
            'oauth_signature_method' => 'HMAC-SHA256',
            'oauth_timestamp' => (string) time(),
        ];

        $params['oauth_signature'] = self::oauthSignature($method, $url, $params, $this->consumerSecret);

        return $params;
    }

    /** @param array<string, mixed> $params All query parameters except oauth_signature. */
    public static function oauthSignature(string $method, string $url, array $params, string $consumerSecret): string
    {
        uksort($params, 'strcmp');
        $encoded = array_combine(self::encode(array_keys($params)), self::encode(array_values($params)));

        $baseString = strtoupper($method).'&'.rawurlencode($url).'&'.implode('%26', self::joinWithEquals($encoded));
        $algorithm = strtolower(str_replace('HMAC-', '', $params['oauth_signature_method']));

        return base64_encode(hash_hmac($algorithm, $baseString, $consumerSecret.'&', true));
    }

    /** Same output as wc_rest_urlencode_rfc3986(). */
    private static function encode(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::encode(...), $value);
        }

        return str_replace(['+', '%7E'], [' ', '~'], rawurlencode((string) $value));
    }

    /** Same output as WC_REST_Authentication::join_with_equals_sign(). */
    private static function joinWithEquals(array $params, array $pairs = [], string $parent = ''): array
    {
        foreach ($params as $key => $value) {
            if ($parent !== '') {
                $key = $parent.'%5B'.$key.'%5D';
            }
            if (is_array($value)) {
                $pairs = self::joinWithEquals($value, $pairs, (string) $key);
            } else {
                $pairs[] = self::encode($key.'='.$value);
            }
        }

        return $pairs;
    }

    /**
     * Make query values exactly what PHP will see in $_GET on the WordPress side,
     * so the signed string matches: drop nulls, booleans as 1/0, everything else as string.
     */
    private static function normalizeQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => self::normalizeQuery($value),
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }

        return $out;
    }
}
