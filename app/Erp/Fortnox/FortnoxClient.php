<?php

namespace App\Erp\Fortnox;

use App\Erp\ErpException;
use App\Models\ErpConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Fortnox API v3 with OAuth 2.0 (authorization code flow).
 *
 * - Access tokens live 1 hour; refresh tokens 45 days and are ROTATED on every refresh,
 *   so a refresh must happen once, under a lock, and the new refresh token saved at once.
 * - Rate limit: 25 requests / 5 s per token. 429 and 5xx are retried with backoff.
 */
class FortnoxClient
{
    public const DRIVER = 'fortnox';

    private const RETRY_DELAYS_MS = [1000, 3000];

    public function __construct(private readonly HttpFactory $http, private readonly array $config) {}

    public function isConfigured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']);
    }

    public function connection(): ?ErpConnection
    {
        return ErpConnection::where('driver', self::DRIVER)->first();
    }

    public function authorizeUrl(string $state): string
    {
        return $this->config['auth_url'].'?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->config['scopes'],
            'state' => $state,
            'access_type' => 'offline',
            'response_type' => 'code',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Exchange the code from the OAuth callback for tokens and store them. */
    public function connect(string $code): ErpConnection
    {
        $tokens = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->config['redirect_uri']]);

        return ErpConnection::updateOrCreate(['driver' => self::DRIVER], [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => $tokens['scope'] ?? $this->config['scopes'],
            'connected_at' => now(),
            'refreshed_at' => null,
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query)->json() ?? [];
    }

    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, [], $body)->json() ?? [];
    }

    public function send(string $method, string $path, array $query = [], ?array $body = null, bool $retriedAuth = false): Response
    {
        $url = rtrim($this->config['api_url'], '/').'/'.ltrim($path, '/');

        for ($attempt = 0; ; $attempt++) {
            try {
                $request = $this->http->withToken($this->accessToken())
                    ->acceptJson()->asJson()
                    ->timeout((int) ($this->config['timeout'] ?? 20))
                    ->withUserAgent('VerdantCart-B2B-Sync/0.1');

                $response = match ($method) {
                    'GET' => $request->get($url, $query),
                    'POST' => $request->post($url, $body ?? []),
                    'PUT' => $request->put($url, $body ?? []),
                };
            } catch (ConnectionException $e) {
                if ($attempt < count(self::RETRY_DELAYS_MS)) {
                    Sleep::usleep(self::RETRY_DELAYS_MS[$attempt] * 1000);

                    continue;
                }
                throw new ErpException('Could not reach Fortnox: '.$e->getMessage(), retryable: true);
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt < count(self::RETRY_DELAYS_MS)) {
                Sleep::usleep(self::RETRY_DELAYS_MS[$attempt] * 1000);

                continue;
            }

            // Token revoked or expired early: refresh once and repeat the call.
            if ($response->status() === 401 && ! $retriedAuth) {
                $this->refresh(force: true);

                return $this->send($method, $path, $query, $body, retriedAuth: true);
            }

            if ($response->failed()) {
                throw $this->errorFrom($response, "{$method} /{$path}");
            }

            return $response;
        }
    }

    private function accessToken(): string
    {
        $connection = $this->connection();
        if ($connection === null) {
            throw new ErpException('Fortnox is not connected. Open /fortnox/connect to log in to Fortnox.');
        }

        return $connection->expires_at->gt(now()->addMinute())
            ? $connection->access_token
            : $this->refresh()->access_token;
    }

    /**
     * Refresh the access token. A lock makes sure only one worker uses the (single-use)
     * refresh token; the others wait and then read the new tokens from the database.
     */
    public function refresh(bool $force = false): ErpConnection
    {
        return Cache::lock('fortnox:token-refresh', 30)->block(20, function () use ($force) {
            return DB::transaction(function () use ($force) {
                $connection = ErpConnection::where('driver', self::DRIVER)->lockForUpdate()->first();
                if ($connection === null) {
                    throw new ErpException('Fortnox is not connected. Open /fortnox/connect to log in to Fortnox.');
                }
                if (! $force && $connection->expires_at->gt(now()->addMinute())) {
                    return $connection; // another worker refreshed while we waited
                }

                $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);

                $connection->update([
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                    'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                    'refreshed_at' => now(),
                ]);

                return $connection;
            });
        });
    }

    private function tokenRequest(array $form): array
    {
        if (! $this->isConfigured()) {
            throw new ErpException('Fortnox is not configured. Set FORTNOX_CLIENT_ID and FORTNOX_CLIENT_SECRET in .env.');
        }

        try {
            $response = $this->http->asForm()->acceptJson()
                ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                ->timeout((int) ($this->config['timeout'] ?? 20))
                ->post($this->config['token_url'], $form);
        } catch (ConnectionException $e) {
            throw new ErpException('Could not reach Fortnox login: '.$e->getMessage(), retryable: true);
        }

        if ($response->failed() || empty($response['access_token'])) {
            $reason = $response['error_description'] ?? $response['error'] ?? 'HTTP '.$response->status();
            $hint = ($response['error'] ?? null) === 'invalid_grant'
                ? ' The Fortnox login has expired or was revoked; reconnect at /fortnox/connect.'
                : '';

            throw new ErpException("Fortnox token request failed: {$reason}.{$hint}", retryable: $response->serverError(), status: $response->status());
        }

        return $response->json();
    }

    /** Fortnox errors look like {"ErrorInformation": {"Error": 1, "Message": "...", "Code": 2000204}} (key case varies). */
    private function errorFrom(Response $response, string $what): ErpException
    {
        $info = array_change_key_case((array) ($response->json('ErrorInformation') ?? $response->json('errorInformation') ?? []));
        $message = $info['message'] ?? mb_strimwidth(trim(strip_tags($response->body())), 0, 200, '…');
        $code = isset($info['code']) ? " (code {$info['code']})" : '';

        return new ErpException(
            "Fortnox {$what} failed with HTTP {$response->status()}{$code}: {$message}",
            retryable: $response->status() === 429 || $response->serverError(),
            status: $response->status(),
        );
    }
}
