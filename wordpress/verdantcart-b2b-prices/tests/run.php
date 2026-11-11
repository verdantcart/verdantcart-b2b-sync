<?php

use VerdantCart\B2bPrices\ApiClient;
use VerdantCart\B2bPrices\PriceCache;
use VerdantCart\B2bPrices\Settings;

require __DIR__.'/bootstrap.php';

function b2bAnswer(array $prices, array $missing = [], bool $isB2b = true, int $ttl = 300): array
{
    return [
        'data' => [
            'is_b2b' => $isB2b,
            'tier' => $isB2b ? ['slug' => 'gold', 'name' => 'Guld', 'discount_percent' => '10.00'] : null,
            'prices' => array_map(fn ($p) => ['currency' => 'SEK', 'base_price' => '0.00', 'unit_price' => $p], $prices),
            'missing' => $missing,
        ],
        'meta' => ['cache_ttl' => $ttl],
    ];
}

function api(): ApiClient
{
    return new ApiClient('https://b2b.example.test/', 'secret-token');
}

$tests = [
    'not configured returns an error and makes no request' => function () {
        $result = (new ApiClient('', ''))->prices(42, ['A']);
        check($result instanceof WP_Error && $result->get_error_code() === 'vcb2b_not_configured', 'expected not_configured');
        check(WpFake::$requests === [], 'no request expected');
    },

    'sends the bearer token and the SKUs, and reads the prices' => function () {
        WpFake::respond(200, b2bAnswer(['VC-KAF-4735' => '162.00'], ['NOPE']));
        $result = api()->prices(42, ['VC-KAF-4735', 'NOPE']);

        $request = WpFake::$requests[0];
        check($request['url'] === 'https://b2b.example.test/api/woo-customers/42/prices?skus=VC-KAF-4735%2CNOPE', 'url was '.$request['url']);
        check($request['args']['headers']['Authorization'] === 'Bearer secret-token', 'bearer header');
        check($request['args']['timeout'] <= 3, 'short timeout');
        check($result['is_b2b'] === true && $result['tier'] === 'gold', 'is_b2b + tier');
        check($result['prices'] === ['VC-KAF-4735' => '162.00'], 'prices');
        check($result['missing'] === ['NOPE'], 'missing');
    },

    'a refused token is reported as an auth problem' => function () {
        WpFake::respond(403, ['message' => 'Invalid ability provided.']);
        $result = api()->prices(42, ['A']);
        check($result instanceof WP_Error && $result->get_error_code() === 'vcb2b_auth', 'expected vcb2b_auth');
    },

    'an answer that is not B2B Sync JSON is rejected' => function () {
        WpFake::respond(200, '<html>Maintenance</html>');
        $result = api()->prices(42, ['A']);
        check($result instanceof WP_Error && $result->get_error_code() === 'vcb2b_bad_response', 'expected bad_response');
    },

    'more than 100 SKUs are split into several calls' => function () {
        $skus = array_map(fn ($i) => "SKU-{$i}", range(1, 150));
        WpFake::respond(200, b2bAnswer(['SKU-1' => '10.00']));
        WpFake::respond(200, b2bAnswer(['SKU-150' => '20.00']));
        $result = api()->prices(42, $skus);

        check(count(WpFake::$requests) === 2, 'expected 2 requests, got '.count(WpFake::$requests));
        check($result['prices'] === ['SKU-1' => '10.00', 'SKU-150' => '20.00'], 'merged prices');
    },

    'cache: the same SKU is fetched once' => function () {
        $cache = new PriceCache(api());
        WpFake::respond(200, b2bAnswer(['A' => '162.00']));

        check($cache->unitPrice(42, 'A') === '162.00', 'first lookup');
        check($cache->unitPrice(42, 'A') === '162.00', 'second lookup');
        check(count(WpFake::$requests) === 1, 'expected 1 request, got '.count(WpFake::$requests));
    },

    'cache: only SKUs not seen before are fetched' => function () {
        $cache = new PriceCache(api());
        WpFake::respond(200, b2bAnswer(['A' => '162.00']));
        WpFake::respond(200, b2bAnswer(['B' => '90.00']));

        $cache->prices(42, ['A']);
        $prices = $cache->prices(42, ['A', 'B']);

        check($prices === ['A' => '162.00', 'B' => '90.00'], 'both prices');
        check(str_ends_with(WpFake::$requests[1]['url'], 'skus=B'), 'second call asks only for B: '.WpFake::$requests[1]['url']);
    },

    'cache: a shopper who is not a B2B customer costs one call and gets no prices' => function () {
        $cache = new PriceCache(api());
        WpFake::respond(200, b2bAnswer([], [], false));

        check($cache->prices(7, ['A']) === [], 'no prices');
        check($cache->prices(7, ['B', 'C']) === [], 'still no prices');
        check(count(WpFake::$requests) === 1, 'expected 1 request');
    },

    'cache: unknown SKUs are remembered and not asked for again' => function () {
        $cache = new PriceCache(api());
        WpFake::respond(200, b2bAnswer([], ['NOPE']));

        check($cache->unitPrice(42, 'NOPE') === null, 'null for unknown');
        check($cache->unitPrice(42, 'NOPE') === null, 'still null');
        check(count(WpFake::$requests) === 1, 'expected 1 request');
    },

    'cache: when B2B Sync fails the shop keeps its prices and calls pause for a minute' => function () {
        $cache = new PriceCache(api());
        WpFake::$responses[] = new WP_Error('http_request_failed', 'timed out');

        check($cache->unitPrice(42, 'A') === null, 'fallback to shop price');
        check($cache->unitPrice(42, 'B') === null, 'still paused');
        check(count(WpFake::$requests) === 1, 'no second request while paused');
        check(get_transient(PriceCache::DOWN_KEY) === 1, 'down flag set');
        check(count(WpFake::$logs) === 1 && str_contains(WpFake::$logs[0], 'timed out'), 'failure logged');
    },

    'cache: guests (customer 0) never trigger a call' => function () {
        $cache = new PriceCache(api());
        check($cache->prices(0, ['A']) === [], 'no prices for guests');
        check(WpFake::$requests === [], 'no request');
    },

    'cache: clearing the cache fetches prices again' => function () {
        $cache = new PriceCache(api());
        WpFake::respond(200, b2bAnswer(['A' => '162.00']));
        WpFake::respond(200, b2bAnswer(['A' => '150.00']));

        $cache->unitPrice(42, 'A');
        PriceCache::clearAll();

        check($cache->unitPrice(42, 'A') === '150.00', 'new price after clear');
    },

    'settings: only https, or http for local development hosts' => function () {
        check(Settings::sanitizeUrl('https://b2b.fly.dev/') === 'https://b2b.fly.dev', 'https ok, trailing slash removed');
        check(Settings::sanitizeUrl('http://verdantcart-b2b-sync.test') === 'http://verdantcart-b2b-sync.test', 'http .test ok');
        check(Settings::sanitizeUrl('http://localhost:8000') === 'http://localhost:8000', 'http localhost ok');
        check(Settings::sanitizeUrl('http://b2b.fly.dev') === null, 'plain http on the internet refused');
        check(Settings::sanitizeUrl('javascript:alert(1)') === null, 'junk refused');
        check(Settings::sanitizeUrl('') === '', 'empty allowed');
    },
];

function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

$failed = 0;
foreach ($tests as $name => $test) {
    WpFake::reset();
    try {
        $test();
        echo "  ✓ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ {$name}\n      {$e->getMessage()}\n";
    }
}

$total = count($tests);
echo $failed === 0 ? "\nOK ({$total} tests)\n" : "\nFAILED ({$failed} of {$total})\n";
exit($failed === 0 ? 0 : 1);
