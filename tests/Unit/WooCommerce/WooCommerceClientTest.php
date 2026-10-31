<?php

namespace Tests\Unit\WooCommerce;

use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\Support\WooOAuthVerifier;
use Tests\TestCase;

class WooCommerceClientTest extends TestCase
{
    private const KEY = 'ck_test_0123456789abcdef';

    private const SECRET = 'cs_test_fedcba9876543210';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    private function client(string $baseUrl): WooCommerceClient
    {
        return new WooCommerceClient(app(HttpFactory::class), $baseUrl, self::KEY, self::SECRET);
    }

    public function test_https_store_uses_basic_auth_and_no_oauth_params(): void
    {
        Http::fake(['shop.example.se/*' => Http::response([['id' => 1]])]);

        $this->client('https://shop.example.se')->get('products', ['per_page' => 5]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://shop.example.se/wp-json/wc/v3/products?per_page=5'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::KEY.':'.self::SECRET))
                && ! str_contains($request->url(), 'oauth_');
        });
    }

    public function test_http_store_requests_are_signed_so_woocommerce_accepts_them(): void
    {
        Http::fake(['woo-b2b.test/*' => Http::response([])]);
        $client = $this->client('http://woo-b2b.test/');

        $client->get('products', [
            'search' => 'Kaffe mörkrost 1 kg',      // spaces + non-ASCII
            'sku' => 'VC-KAF-1001,VC-TEA-2002',     // comma list
            'include' => [12, 15],                   // array param
            'featured' => true,                      // boolean
            'after' => '2026-10-01T00:00:00',        // colons
            'status' => null,                        // dropped
            'per_page' => 100,
        ]);
        $client->post('orders', ['status' => 'processing'], ['force' => false]);
        $client->delete('products/42', ['force' => true]);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
        foreach (Http::recorded() as [$request]) {
            $this->assertStringContainsString('oauth_signature_method=HMAC-SHA256', $request->url());
            $this->assertTrue(
                WooOAuthVerifier::accepts($request->method(), $request->url(), self::SECRET),
                "WooCommerce would reject: {$request->method()} {$request->url()}"
            );
            $this->assertFalse(
                WooOAuthVerifier::accepts($request->method(), $request->url(), 'cs_wrong_secret'),
                'Signature must depend on the consumer secret'
            );
            $tampered = str_replace('per_page=100', 'per_page=99', $request->url());
            if ($tampered !== $request->url()) {
                $this->assertFalse(WooOAuthVerifier::accepts($request->method(), $tampered, self::SECRET),
                    'Changing a signed parameter must invalidate the signature');
            }
        }
    }

    public function test_post_body_is_sent_as_json(): void
    {
        Http::fake(['shop.example.se/*' => Http::response(['id' => 99], 201)]);

        $created = $this->client('https://shop.example.se')->post('products', ['name' => 'Te', 'regular_price' => '49.00']);

        $this->assertSame(99, $created['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['regular_price'] === '49.00' && $r->isJson());
    }

    public function test_rate_limits_and_server_errors_are_retried_and_each_attempt_is_signed_again(): void
    {
        Http::fake(['woo-b2b.test/*' => Http::sequence()
            ->push(['code' => 'too_many_requests'], 429)
            ->push('Service Unavailable', 503)
            ->push([['id' => 7]], 200)]);

        $result = $this->client('http://woo-b2b.test')->get('orders');

        $this->assertSame(7, $result[0]['id']);
        Http::assertSentCount(3);
        $nonces = collect(Http::recorded())->map(function ($pair) {
            parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $q);

            return $q['oauth_nonce'];
        });
        $this->assertCount(3, $nonces->unique(), 'A reused nonce would be rejected by WooCommerce');
        Sleep::assertSleptTimes(2);
    }

    public function test_gives_up_after_two_retries(): void
    {
        Http::fake(['shop.example.se/*' => Http::response('down', 502)]);

        try {
            $this->client('https://shop.example.se')->get('orders');
            $this->fail('Expected exception');
        } catch (WooCommerceException $e) {
            $this->assertSame(502, $e->status);
            $this->assertTrue($e->isRetryable());
        }
        Http::assertSentCount(3);
    }

    public function test_client_errors_are_not_retried_and_keep_woocommerce_error_code(): void
    {
        Http::fake(['shop.example.se/*' => Http::response([
            'code' => 'woocommerce_rest_authentication_error',
            'message' => 'Consumer key is invalid.',
            'data' => ['status' => 401],
        ], 401)]);

        try {
            $this->client('https://shop.example.se')->get('products');
            $this->fail('Expected exception');
        } catch (WooCommerceException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('woocommerce_rest_authentication_error', $e->wooCode);
            $this->assertStringContainsString('Consumer key is invalid.', $e->getMessage());
            $this->assertFalse($e->isRetryable());
        }
        Http::assertSentCount(1);
    }

    public function test_connection_failure_becomes_a_woocommerce_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->expectException(WooCommerceException::class);
        $this->expectExceptionMessage('Could not reach WooCommerce at https://shop.example.se');

        $this->client('https://shop.example.se')->get('products');
    }

    public function test_paginate_walks_all_pages(): void
    {
        Http::fake(['shop.example.se/*' => Http::sequence()
            ->push([['id' => 1], ['id' => 2]], 200, ['X-WP-TotalPages' => '2'])
            ->push([['id' => 3]], 200, ['X-WP-TotalPages' => '2'])]);

        $ids = $this->client('https://shop.example.se')->paginate('products', perPage: 2)->pluck('id')->all();

        $this->assertSame([1, 2, 3], $ids);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'page=2'));
    }

    public function test_total_reads_the_x_wp_total_header(): void
    {
        Http::fake(['shop.example.se/*' => Http::response([['id' => 1]], 200, ['X-WP-Total' => '137'])]);

        $this->assertSame(137, $this->client('https://shop.example.se')->total('orders'));
    }

    public function test_missing_configuration_is_reported_clearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WOO_BASE_URL');

        new WooCommerceClient(app(HttpFactory::class), '', self::KEY, self::SECRET);
    }
}
