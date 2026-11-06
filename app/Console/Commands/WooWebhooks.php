<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceClient;
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
    public const TOPICS = ['order.created', 'product.created', 'product.updated'];

    public function handle(): int
    {
        try {
            $woo = app(WooCommerceClient::class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $secret = $this->ensureSecret();
        $url = $this->option('url') ?: $this->defaultUrl();
        $this->components->info("Delivery URL: {$url}");

        try {
            $existing = collect($woo->get('webhooks', ['per_page' => 100]));
            $rows = [];

            foreach (self::TOPICS as $topic) {
                $match = $existing->first(fn (array $w) => $w['topic'] === $topic && rtrim($w['delivery_url'], '/') === rtrim($url, '/'));
                $data = [
                    'name' => "VerdantCart B2B Sync – {$topic}",
                    'topic' => $topic,
                    'delivery_url' => $url,
                    'secret' => $secret,
                    'status' => 'active',
                ];

                $webhook = $match
                    ? $woo->put("webhooks/{$match['id']}", $data)
                    : $woo->post('webhooks', $data);

                $rows[] = [$webhook['id'], $topic, $webhook['status'], $match ? 'updated' : 'created'];
            }
        } catch (WooCommerceException $e) {
            $this->components->error($e->getMessage());
            $this->line('  Is `php artisan serve` running, and can the store reach the delivery URL?');

            return self::FAILURE;
        }

        $this->table(['Woo ID', 'Topic', 'Status', ''], $rows);
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

    /**
     * APP_URL + /webhooks/woocommerce. "localhost" is swapped for 127.0.0.1 because Windows may
     * resolve localhost to IPv6 (::1) while `php artisan serve` only listens on 127.0.0.1.
     */
    private function defaultUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return preg_replace('#^(https?://)localhost\b#', '${1}127.0.0.1', $base).'/webhooks/woocommerce';
    }
}
