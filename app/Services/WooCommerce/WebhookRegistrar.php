<?php

namespace App\Services\WooCommerce;

/**
 * Creates or repairs the WooCommerce webhooks this app listens to.
 * Used by `php artisan woo:webhooks` and by the setup guide.
 */
class WebhookRegistrar
{
    /** Topics this app handles. */
    public const TOPICS = ['order.created', 'product.created', 'product.updated'];

    public function __construct(private readonly WooCommerceClient $woo) {}

    /**
     * APP_URL + /webhooks/woocommerce. "localhost" is swapped for 127.0.0.1 because Windows may
     * resolve localhost to IPv6 (::1) while `php artisan serve` only listens on 127.0.0.1.
     */
    public static function defaultUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return preg_replace('#^(https?://)localhost\b#', '${1}127.0.0.1', $base).'/webhooks/woocommerce';
    }

    /**
     * @return list<array{id: int|string, topic: string, status: string, action: 'created'|'updated'}>
     *
     * @throws WooCommerceException
     */
    public function register(string $url, string $secret): array
    {
        $existing = collect($this->woo->get('webhooks', ['per_page' => 100]));
        $rows = [];

        foreach (self::TOPICS as $topic) {
            $match = $existing->first(fn (array $w) => $this->matches($w, $topic, $url));
            $data = [
                'name' => "VerdantCart B2B Sync – {$topic}",
                'topic' => $topic,
                'delivery_url' => $url,
                'secret' => $secret,
                'status' => 'active',
            ];

            $webhook = $match
                ? $this->woo->put("webhooks/{$match['id']}", $data)
                : $this->woo->post('webhooks', $data);

            $rows[] = ['id' => $webhook['id'], 'topic' => $topic, 'status' => (string) $webhook['status'], 'action' => $match ? 'updated' : 'created'];
        }

        return $rows;
    }

    /**
     * Topics that have no active webhook pointing at $url.
     *
     * @return list<string>
     *
     * @throws WooCommerceException
     */
    public function missing(string $url): array
    {
        $existing = collect($this->woo->get('webhooks', ['per_page' => 100]));

        return array_values(array_filter(self::TOPICS, fn (string $topic) => ! $existing->contains(
            fn (array $w) => $this->matches($w, $topic, $url) && ($w['status'] ?? '') === 'active'
        )));
    }

    private function matches(array $webhook, string $topic, string $url): bool
    {
        return ($webhook['topic'] ?? null) === $topic
            && rtrim((string) ($webhook['delivery_url'] ?? ''), '/') === rtrim($url, '/');
    }
}
