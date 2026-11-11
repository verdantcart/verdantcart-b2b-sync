# VerdantCart B2B Prices (WordPress plugin)

Shows each wholesale customer their own price in WooCommerce. Prices come from
[VerdantCart B2B Sync](../../README.md), so the price in the shop is the same price that
ends up on the Fortnox order. Everyone else keeps seeing the normal shop price.

**Status:** v0.2. B2B customers see their price on product pages, in shop listings, in
variable-product price ranges, in the cart and at checkout, and WooCommerce charges it.

## Install

1. Copy this folder to `wp-content/plugins/verdantcart-b2b-prices/` and activate the plugin.
2. In B2B Sync, create a token that can only read prices:
   `php artisan api:token "Shop" --ability=prices:read`
3. In WordPress, go to **WooCommerce → B2B Prices** and enter the B2B Sync URL and the token.
   You can also put them in `wp-config.php` instead:
   ```php
   define('VCB2B_API_URL', 'https://your-b2b-sync.fly.dev');
   define('VCB2B_API_TOKEN', '…');
   ```
4. Click **Test**, optionally with a customer ID and a SKU, to see the price that customer pays.

## What the customer sees

A logged-in B2B customer sees the normal price crossed out next to their own price, with the
label "Your price" (change it with the `vcb2b_price_label` filter). Guests, consumers and
wp-admin always see the normal shop price.

Style the label in your theme, for example:

```css
.vcb2b-label { font-size: .8em; color: #2e7d32; margin-left: .4em; }
```

## How it behaves

- **One call per customer per few minutes.** Prices are cached per customer for as long as
  B2B Sync says (`cache_ttl`, 5 minutes by default). Logging in clears that customer's cache.
- **Never slows the shop down.** Calls time out after 3 seconds. If B2B Sync fails, the shop
  shows its own prices and the plugin waits a minute before trying again. Failures are logged
  under WooCommerce → Status → Logs (`verdantcart-b2b-prices`).
- **One call per page.** Shop listings and the cart fetch all their SKUs in one call before
  WooCommerce asks for each price.
- **Consumers never see reseller prices.** WooCommerce caches variable-product price ranges;
  the plugin gives every possible B2B customer their own cache entry.
- **Guests and consumers cost nothing.** Guests never trigger a call, and a logged-in shopper
  who is not a B2B customer is remembered as such.

## Tests

```
php wordpress/verdantcart-b2b-prices/tests/run.php
```
The tests use small stand-ins for the WordPress functions, so no WordPress install is needed.
