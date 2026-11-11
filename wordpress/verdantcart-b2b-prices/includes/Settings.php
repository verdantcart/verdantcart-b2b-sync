<?php

namespace VerdantCart\B2bPrices;

defined('ABSPATH') || exit;

/**
 * WooCommerce → B2B Prices: where B2B Sync runs, its API token, a connection test and a
 * price lookup for one customer. The token can also be set in wp-config.php:
 *
 *     define('VCB2B_API_URL', 'https://your-b2b-sync.fly.dev');
 *     define('VCB2B_API_TOKEN', '…');
 */
final class Settings
{
    public const OPTION_URL = 'vcb2b_api_url';

    public const OPTION_TOKEN = 'vcb2b_api_token';

    public const OPTION_CACHE_GENERATION = 'vcb2b_cache_generation';

    public const PAGE = 'vcb2b-prices';

    public const CAPABILITY = 'manage_woocommerce';

    public static function apiUrl(): string
    {
        return defined('VCB2B_API_URL') ? (string) VCB2B_API_URL : (string) get_option(self::OPTION_URL, '');
    }

    public static function apiToken(): string
    {
        return defined('VCB2B_API_TOKEN') ? (string) VCB2B_API_TOKEN : (string) get_option(self::OPTION_TOKEN, '');
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_vcb2b_save', [self::class, 'save']);
        add_action('admin_post_vcb2b_test', [self::class, 'test']);
        add_action('admin_post_vcb2b_clear', [self::class, 'clear']);
        add_action('admin_notices', [self::class, 'notConfiguredNotice']);
    }

    public static function menu(): void
    {
        add_submenu_page('woocommerce', 'B2B Prices', 'B2B Prices', self::CAPABILITY, self::PAGE, [self::class, 'render']);
    }

    /** Accepts https anywhere, http only for local development hosts. */
    public static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $local = $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        if ($host === '' || ! ($scheme === 'https' || ($scheme === 'http' && $local))) {
            return null;
        }

        return rtrim(esc_url_raw($url), '/');
    }

    public static function save(): void
    {
        self::guard('vcb2b_save');

        $url = self::sanitizeUrl((string) wp_unslash($_POST['api_url'] ?? ''));
        if ($url === null) {
            self::redirect('error', 'The URL must start with https:// (http:// is only allowed for .test and localhost).');
        }
        update_option(self::OPTION_URL, $url, false);

        $token = trim((string) wp_unslash($_POST['api_token'] ?? ''));
        if ($token !== '') {
            update_option(self::OPTION_TOKEN, sanitize_text_field($token), false);
        }

        PriceCache::clearAll();
        self::redirect('success', 'Settings saved.');
    }

    public static function test(): void
    {
        self::guard('vcb2b_test');

        $api = ApiClient::fromSettings();
        $ping = $api->ping();
        if (is_wp_error($ping)) {
            self::redirect('error', 'Connection failed: '.$ping->get_error_message());
        }

        $customerId = absint($_POST['customer_id'] ?? 0);
        $sku = sanitize_text_field((string) wp_unslash($_POST['sku'] ?? ''));
        if ($customerId === 0 || $sku === '') {
            self::redirect('success', 'Connected to B2B Sync.');
        }

        $result = $api->prices($customerId, [$sku]);
        if (is_wp_error($result)) {
            self::redirect('error', 'Lookup failed: '.$result->get_error_message());
        }
        if (! $result['is_b2b']) {
            self::redirect('success', "Connected. Customer #{$customerId} is not an active B2B customer, so the shop keeps its own price.");
        }

        $price = $result['prices'][$sku] ?? null;
        $tier = $result['tier'] ?? 'no tier';
        self::redirect('success', $price === null
            ? "Connected. Customer #{$customerId} ({$tier}): B2B Sync does not know SKU {$sku}."
            : "Connected. Customer #{$customerId} ({$tier}) pays {$price} for {$sku}.");
    }

    public static function clear(): void
    {
        self::guard('vcb2b_clear');
        PriceCache::clearAll();
        self::redirect('success', 'Price cache cleared. Prices are fetched again on the next page view.');
    }

    public static function notConfiguredNotice(): void
    {
        if (! current_user_can(self::CAPABILITY) || ApiClient::fromSettings()->isConfigured()) {
            return;
        }
        $url = admin_url('admin.php?page='.self::PAGE);
        echo '<div class="notice notice-warning"><p>VerdantCart B2B Prices is not connected yet. <a href="'.esc_url($url).'">Add the B2B Sync URL and API token</a>.</p></div>';
    }

    public static function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        $notice = get_transient('vcb2b_notice_'.get_current_user_id());
        if (is_array($notice)) {
            delete_transient('vcb2b_notice_'.get_current_user_id());
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($notice[0]), esc_html($notice[1]));
        }

        $tokenFromConfig = defined('VCB2B_API_TOKEN');
        $urlFromConfig = defined('VCB2B_API_URL');
        $hasToken = self::apiToken() !== '';
        $action = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="wrap">
            <h1>VerdantCart B2B Prices</h1>
            <p>Wholesale customers see their own price from VerdantCart B2B Sync. Everyone else sees the normal shop price.</p>

            <h2>Connection</h2>
            <form method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="action" value="vcb2b_save">
                <?php wp_nonce_field('vcb2b_save'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vcb2b-api-url">B2B Sync URL</label></th>
                        <td>
                            <input type="url" id="vcb2b-api-url" name="api_url" class="regular-text" placeholder="https://your-b2b-sync.fly.dev"
                                   value="<?php echo esc_attr(self::apiUrl()); ?>" <?php disabled($urlFromConfig); ?>>
                            <?php if ($urlFromConfig) { ?><p class="description">Set in wp-config.php (VCB2B_API_URL).</p><?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vcb2b-api-token">API token</label></th>
                        <td>
                            <?php if ($tokenFromConfig) { ?>
                                <p>Set in wp-config.php (VCB2B_API_TOKEN).</p>
                            <?php } else { ?>
                                <input type="password" id="vcb2b-api-token" name="api_token" class="regular-text" autocomplete="new-password"
                                       placeholder="<?php echo $hasToken ? esc_attr('Saved. Leave empty to keep it.') : ''; ?>">
                                <p class="description">Create one in B2B Sync: <code>php artisan api:token "Shop" --ability=prices:read</code></p>
                            <?php } ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Test</h2>
            <form method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="action" value="vcb2b_test">
                <?php wp_nonce_field('vcb2b_test'); ?>
                <p>
                    <label>Customer ID <input type="number" name="customer_id" min="1" class="small-text"></label>
                    <label>SKU <input type="text" name="sku" class="regular-text"></label>
                </p>
                <p class="description">Leave both empty to only test the connection.</p>
                <?php submit_button('Test', 'secondary'); ?>
            </form>

            <h2>Cache</h2>
            <form method="post" action="<?php echo $action; ?>">
                <input type="hidden" name="action" value="vcb2b_clear">
                <?php wp_nonce_field('vcb2b_clear'); ?>
                <p>Prices are kept for a few minutes. Clear them after changing tiers in B2B Sync.</p>
                <?php submit_button('Clear price cache', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    private static function guard(string $nonce): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die('You are not allowed to change B2B Prices.', 403);
        }
        check_admin_referer($nonce);
    }

    /** @return never */
    private static function redirect(string $type, string $message): void
    {
        set_transient('vcb2b_notice_'.get_current_user_id(), [$type, $message], 60);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));
        exit;
    }
}
