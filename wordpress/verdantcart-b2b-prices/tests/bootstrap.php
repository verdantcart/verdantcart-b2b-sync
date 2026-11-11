<?php

/**
 * Minimal stand-ins for the WordPress functions the plugin uses, so the price logic can be
 * tested without a WordPress install: php tests/run.php
 */
define('ABSPATH', __DIR__.'/');

final class WP_Error
{
    public function __construct(private string $code = '', private string $message = '') {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

final class WpFake
{
    public static array $transients = [];

    public static array $options = [];

    /** @var list<array{url: string, args: array}> */
    public static array $requests = [];

    /** @var list<array|WP_Error> */
    public static array $responses = [];

    public static array $logs = [];

    public static function reset(): void
    {
        self::$transients = self::$options = self::$requests = self::$responses = self::$logs = [];
    }

    /** Queue the next HTTP answer from B2B Sync. */
    public static function respond(int $code, array|string $body): void
    {
        self::$responses[] = ['code' => $code, 'body' => is_string($body) ? $body : json_encode($body)];
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}
function get_transient(string $key)
{
    return WpFake::$transients[$key] ?? false;
}
function set_transient(string $key, $value, int $ttl = 0): bool
{
    WpFake::$transients[$key] = $value;

    return true;
}
function delete_transient(string $key): bool
{
    unset(WpFake::$transients[$key]);

    return true;
}
function get_option(string $key, $default = false)
{
    return WpFake::$options[$key] ?? $default;
}
function update_option(string $key, $value, $autoload = null): bool
{
    WpFake::$options[$key] = $value;

    return true;
}
function wp_parse_url(string $url)
{
    return parse_url($url);
}
function esc_url_raw(string $url): string
{
    return $url;
}
function wp_remote_retrieve_response_code(array $response): int
{
    return $response['code'];
}
function wp_remote_retrieve_body(array $response): string
{
    return $response['body'];
}
function wp_remote_get(string $url, array $args = [])
{
    WpFake::$requests[] = ['url' => $url, 'args' => $args];

    return array_shift(WpFake::$responses) ?? new WP_Error('http_request_failed', 'no fake response queued');
}
function wc_get_logger(): object
{
    return new class
    {
        public function warning(string $m, array $c = []): void
        {
            WpFake::$logs[] = $m;
        }
    };
}

require_once dirname(__DIR__).'/includes/ApiClient.php';
require_once dirname(__DIR__).'/includes/PriceCache.php';
require_once dirname(__DIR__).'/includes/Settings.php';
require_once dirname(__DIR__).'/includes/Plugin.php';
