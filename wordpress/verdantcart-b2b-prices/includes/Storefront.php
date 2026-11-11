<?php

namespace VerdantCart\B2bPrices;

use Closure;

defined('ABSPATH') || exit;

/**
 * Puts the B2B price into WooCommerce everywhere a shopper sees or pays a price: product
 * pages, shop listings, variable-product ranges, the cart and the checkout.
 *
 * WooCommerce then charges the B2B price, so the order that reaches B2B Sync already has the
 * right totals and the Fortnox order matches what the customer saw.
 */
final class Storefront
{
    /** Never look up more SKUs than this for one listing page. */
    public const PREFETCH_LIMIT = 200;

    /**
     * @param  Closure(): int  $customerId  the logged-in WooCommerce customer, 0 for guests
     * @param  Closure(): bool  $isShopRequest  false in wp-admin, so editing orders keeps stored prices
     */
    public function __construct(
        private readonly PriceCache $cache,
        private readonly Closure $customerId,
        private readonly Closure $isShopRequest,
    ) {}

    public static function make(PriceCache $cache): self
    {
        return new self(
            $cache,
            static fn (): int => (int) get_current_user_id(),
            static fn (): bool => ! is_admin() || wp_doing_ajax(),
        );
    }

    public function register(): void
    {
        add_filter('woocommerce_product_get_price', [$this, 'filterPrice'], 20, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'filterPrice'], 20, 2);
        add_filter('woocommerce_variation_prices_price', [$this, 'filterVariationPrice'], 20, 3);
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'variationPricesHash'], 20, 3);
        add_filter('woocommerce_get_price_html', [$this, 'priceHtml'], 20, 2);
        add_filter('the_posts', [$this, 'prefetchPosts'], 20, 2);
        add_action('woocommerce_cart_loaded_from_session', [$this, 'prefetchCart']);
    }

    /** The B2B unit price for this product and shopper, or null to keep WooCommerce's price. */
    public function b2bPrice(object $product): ?string
    {
        $customerId = $this->customer();
        if ($customerId === 0) {
            return null;
        }

        $sku = (string) $product->get_sku();

        return $sku === '' ? null : $this->cache->unitPrice($customerId, $sku);
    }

    public function filterPrice($price, $product)
    {
        return $this->b2bPrice($product) ?? $price;
    }

    public function filterVariationPrice($price, $variation, $product)
    {
        return $this->b2bPrice($variation) ?? $price;
    }

    /**
     * WooCommerce caches variable-product price ranges by this hash. Anyone who might be a B2B
     * customer gets their own entry, so a reseller's prices can never be served to consumers.
     */
    public function variationPricesHash(array $hash, $product = null, $forDisplay = false): array
    {
        $customerId = $this->customer();

        if ($customerId !== 0 && $this->cache->knownStatus($customerId) !== false) {
            $hash[] = 'vcb2b-'.$customerId.'-'.PriceCache::generation();
        }

        return $hash;
    }

    /** "<del>180 kr</del> <ins>162 kr</ins> Your price" for simple products and variations. */
    public function priceHtml($html, $product)
    {
        $b2b = $this->b2bPrice($product);
        if ($b2b === null) {
            return $html;
        }

        $label = ' <span class="vcb2b-label">'.esc_html(apply_filters('vcb2b_price_label', __('Your price', 'verdantcart-b2b-prices'))).'</span>';
        $regular = (string) $product->get_regular_price();

        if ($regular === '' || (float) $regular <= (float) $b2b) {
            return wc_price(wc_get_price_to_display($product, ['price' => $b2b])).$product->get_price_suffix().$label;
        }

        return wc_format_sale_price(
            wc_get_price_to_display($product, ['price' => $regular]),
            wc_get_price_to_display($product, ['price' => $b2b]),
        ).$product->get_price_suffix().$label;
    }

    /** Fetch every SKU on a shop listing in one call instead of one call per product. */
    public function prefetchPosts($posts, $query = null)
    {
        $customerId = $this->customer();
        if ($customerId === 0 || ! is_array($posts) || $posts === []) {
            return $posts;
        }

        $skus = [];
        foreach ($posts as $post) {
            if (($post->post_type ?? '') !== 'product' || ! function_exists('wc_get_product')) {
                continue;
            }
            $product = wc_get_product($post->ID);
            if (! $product) {
                continue;
            }
            $skus[] = (string) $product->get_sku();
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $childId) {
                    $child = wc_get_product($childId);
                    $skus[] = $child ? (string) $child->get_sku() : '';
                }
            }
            if (count($skus) >= self::PREFETCH_LIMIT) {
                break;
            }
        }

        $this->cache->prices($customerId, array_slice($skus, 0, self::PREFETCH_LIMIT));

        return $posts;
    }

    /** One call for the whole cart before WooCommerce asks for each line's price. */
    public function prefetchCart($cart): void
    {
        $customerId = $this->customer();
        if ($customerId === 0 || ! is_object($cart) || ! method_exists($cart, 'get_cart')) {
            return;
        }

        $skus = [];
        foreach ($cart->get_cart() as $item) {
            if (isset($item['data']) && is_object($item['data'])) {
                $skus[] = (string) $item['data']->get_sku();
            }
        }

        $this->cache->prices($customerId, $skus);
    }

    private function customer(): int
    {
        return ($this->isShopRequest)() ? max(0, ($this->customerId)()) : 0;
    }
}
