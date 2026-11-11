<?php

namespace VerdantCart\B2bPrices;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Talks to VerdantCart B2B Sync: GET /api/woo-customers/{id}/prices?skus=A,B,C
 *
 * Short timeout on purpose: a slow price service must never make the shop slow.
 */
final class ApiClient
{
    public const MAX_SKUS_PER_CALL = 100;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly float $timeout = 3.0,
    ) {}

    public static function fromSettings(): self
    {
        return new self(Settings::apiUrl(), Settings::apiToken());
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * @param  list<string>  $skus
     * @return array{is_b2b: bool, tier: ?string, prices: array<string, string>, missing: list<string>, cache_ttl: int}|WP_Error
     */
    public function prices(int $wooCustomerId, array $skus): array|WP_Error
    {
        if (! $this->isConfigured()) {
            return new WP_Error('vcb2b_not_configured', 'B2B Sync URL or API token is missing.');
        }

        $result = ['is_b2b' => false, 'tier' => null, 'prices' => [], 'missing' => [], 'cache_ttl' => 300];

        foreach (array_chunk(array_values($skus), self::MAX_SKUS_PER_CALL) as $chunk) {
            $page = $this->fetch($wooCustomerId, $chunk);
            if ($page instanceof WP_Error) {
                return $page;
            }

            $result['is_b2b'] = $page['is_b2b'];
            $result['tier'] = $page['tier'];
            $result['prices'] += $page['prices'];
            $result['missing'] = array_merge($result['missing'], $page['missing']);
            $result['cache_ttl'] = $page['cache_ttl'];

            if (! $page['is_b2b']) {
                break; // not a B2B customer: the other chunks would say the same
            }
        }

        return $result;
    }

    /** A cheap authenticated call, used by the settings page's "Test connection" button. */
    public function ping(): true|WP_Error
    {
        $result = $this->prices(0, ['vcb2b-ping']);

        return $result instanceof WP_Error ? $result : true;
    }

    /** @param list<string> $skus */
    private function fetch(int $wooCustomerId, array $skus): array|WP_Error
    {
        $url = rtrim($this->baseUrl, '/').'/api/woo-customers/'.$wooCustomerId.'/prices?skus='.rawurlencode(implode(',', $skus));

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('vcb2b_unreachable', 'B2B Sync did not answer: '.$response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 || $code === 403) {
            return new WP_Error('vcb2b_auth', 'B2B Sync refused the API token (HTTP '.$code.'). It needs the prices:read ability.');
        }
        if ($code !== 200) {
            return new WP_Error('vcb2b_http', 'B2B Sync answered HTTP '.$code.'.');
        }

        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (! is_array($data) || ! array_key_exists('is_b2b', $data)) {
            return new WP_Error('vcb2b_bad_response', 'B2B Sync sent an answer this plugin does not understand.');
        }

        $prices = [];
        foreach ((array) ($data['prices'] ?? []) as $sku => $price) {
            if (is_array($price) && isset($price['unit_price']) && is_numeric($price['unit_price'])) {
                $prices[(string) $sku] = (string) $price['unit_price'];
            }
        }

        return [
            'is_b2b' => (bool) $data['is_b2b'],
            'tier' => isset($data['tier']['slug']) ? (string) $data['tier']['slug'] : null,
            'prices' => $prices,
            'missing' => array_map('strval', (array) ($data['missing'] ?? [])),
            'cache_ttl' => (int) ($json['meta']['cache_ttl'] ?? 300),
        ];
    }
}
