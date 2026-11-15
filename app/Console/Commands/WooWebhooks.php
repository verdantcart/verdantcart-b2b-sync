<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WebhookRegistrar;
use App\Services\WooCommerce\WooCommerceException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates (or repairs) the WooCommerce webhooks this app listens to, via the REST API.
 * Generates WOO_WEBHOOK_SECRET in .env if it is empty, the same way key:generate works.
 */
#[Signature('woo:webhooks {--url= : Delivery URL (default: APP_URL + /webhooks/woocommerce)}')]
#[Description('Register the WooCommerce webhooks (order.created, product.created, product.updated) that point at this app')]
class WooWebhooks extends Command
{
    /** Topics this app handles. */
    public const TOPICS = WebhookRegistrar::TOPICS;

    public function handle(): int
    {
        try {
            $registrar = app(WebhookRegistrar::class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $secret = $this->ensureSecret();
        $url = $this->option('url') ?: WebhookRegistrar::defaultUrl();
        $this->components->info("Delivery URL: {$url}");

        try {
            $rows = $registrar->register($url, $secret);
        } catch (WooCommerceException $e) {
            $this->components->error($e->getMessage());
            $this->line('  Is `php artisan serve` running, and can the store reach the delivery URL?');

            return self::FAILURE;
        }

        $this->table(['Woo ID', 'Topic', 'Status', ''], array_map('array_values', $rows));
        $this->components->info('Webhooks registered. Place a test order to try them.');

        return self::SUCCESS;
    }

    /** Use the configured secret, or generate one and save it to .env. */
    private function ensureSecret(): string
    {
        $secret = (string) config('services.woocommerce.webhook_secret');
        if ($secret !== '') {
            return $secret;
        }

        $secret = Str::random(48);
        $envPath = app()->environmentFilePath();
        $env = file_exists($envPath) ? file_get_contents($envPath) : '';
        $line = "WOO_WEBHOOK_SECRET={$secret}";

        $env = preg_match('/^[ \t]*WOO_WEBHOOK_SECRET=.*$/m', $env)
            ? preg_replace('/^[ \t]*WOO_WEBHOOK_SECRET=.*$/m', $line, $env)
            : rtrim($env, "\r\n").PHP_EOL.$line.PHP_EOL;

        file_put_contents($envPath, $env);
        config(['services.woocommerce.webhook_secret' => $secret]);
        $this->components->info('Generated WOO_WEBHOOK_SECRET and saved it to .env');

        return $secret;
    }
}
