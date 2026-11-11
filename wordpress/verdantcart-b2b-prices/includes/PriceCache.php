<?php

namespace VerdantCart\B2bPrices;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * One transient per customer holding the prices fetched so far, so a product page, the cart
 * and the checkout reuse the same answer instead of calling B2B Sync again.
 *
 * - Prices are kept for the lifetime B2B Sync asks for (meta.cache_ttl, 30 s to 1 h).
 * - Shoppers who are not B2B customers are remembered too, so they cost one call per period.
 * - When B2B Sync fails, calls stop for a minute and the shop keeps its own prices.
 */
final class PriceCache
{
    public const DOWN_KEY = 'vcb2b_api_down';

    public const DOWN_SECONDS = 60;

    /** @var array<string, array> transient key => entry, so one page view reads each transient once */
    private array $memo = [];

    public function __construct(private readonly ApiClient $api) {}

    /**
     * What we already know about a customer without asking B2B Sync:
     * true = B2B customer, false = not one, null = not looked up yet.
     */
    public function knownStatus(int $customerId): ?bool
    {
        return $customerId > 0 ? $this->load($customerId)['is_b2b'] : false;
    }

    public function unitPrice(int $customerId, string $sku): ?string
    {
        return $this->prices($customerId, [$sku])[$sku] ?? null;
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, string> SKU => B2B unit price, only for SKUs that have one
     */
    public function prices(int $customerId, array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn ($sku) => trim((string) $sku),
            $skus,
        ), static fn (string $sku) => $sku !== '')));

        if ($customerId <= 0 || $skus === []) {
            return [];
        }

        $entry = $this->load($customerId);

        if ($entry['is_b2b'] !== false) {
            $known = array_merge(array_keys($entry['prices']), $entry['unknown']);
            $needed = array_values(array_diff($skus, $known));

            if ($needed !== [] && ! get_transient(self::DOWN_KEY)) {
                $entry = $this->fetchInto($entry, $customerId, $needed);
            }
        }

        if ($entry['is_b2b'] !== true) {
            return [];
        }

        $prices = [];
        foreach ($skus as $sku) {
            if (isset($entry['prices'][$sku])) {
                $prices[$sku] = $entry['prices'][$sku];
            }
        }

        return $prices;
    }

    public function forget(int $customerId): void
    {
        unset($this->memo[self::key($customerId)]);
        delete_transient(self::key($customerId));
    }

    public static function generation(): int
    {
        return (int) get_option(Settings::OPTION_CACHE_GENERATION, 1);
    }

    /** The generation number lets "Clear price cache" drop every customer's prices at once. */
    public static function key(int $customerId): string
    {
        return 'vcb2b_c_'.self::generation().'_'.$customerId;
    }

    public static function clearAll(): void
    {
        update_option(Settings::OPTION_CACHE_GENERATION, (int) get_option(Settings::OPTION_CACHE_GENERATION, 1) + 1, false);
        delete_transient(self::DOWN_KEY);
    }

    /** @return array{is_b2b: ?bool, tier: ?string, prices: array<string, string>, unknown: list<string>, expires: int} */
    private function load(int $customerId): array
    {
        $key = self::key($customerId);
        $entry = $this->memo[$key] ?? get_transient($key);

        if (! is_array($entry) || ($entry['expires'] ?? 0) <= time()) {
            $entry = ['is_b2b' => null, 'tier' => null, 'prices' => [], 'unknown' => [], 'expires' => 0];
        }

        return $this->memo[$key] = $entry;
    }

    private function fetchInto(array $entry, int $customerId, array $skus): array
    {
        $result = $this->api->prices($customerId, $skus);

        if ($result instanceof WP_Error) {
            set_transient(self::DOWN_KEY, 1, self::DOWN_SECONDS);
            Plugin::log('Price lookup failed, using shop prices for '.self::DOWN_SECONDS.' s: '.$result->get_error_message());

            return $entry;
        }

        if ($entry['expires'] === 0) {
            $entry['expires'] = time() + max(30, min(3600, $result['cache_ttl']));
        }

        $entry['is_b2b'] = $result['is_b2b'];
        $entry['tier'] = $result['tier'];
        $entry['prices'] = $result['prices'] + $entry['prices'];
        $entry['unknown'] = array_values(array_unique(array_merge($entry['unknown'], $result['missing'])));

        $key = self::key($customerId);
        $this->memo[$key] = $entry;
        set_transient($key, $entry, max(1, $entry['expires'] - time()));

        return $entry;
    }
}
