<?php

namespace App\Services\Setup;

use App\Erp\Fortnox\FortnoxClient;
use App\Erp\Fortnox\LicenceCheck;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\WooCommerce\WebhookRegistrar;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\WooCommerce\WooCommerceException;
use App\Support\StockBuffer;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * The five steps that take a fresh installation to "orders and prices flow":
 * WooCommerce, webhooks, Fortnox, stock buffer, storefront token.
 *
 * Building the page only reads local state and the result of the last check.
 * WooCommerce and Fortnox are called only when the admin presses a check button.
 */
class SetupGuide
{
    public const WOO_CHECK = 'setup.woo_check';

    public const FORTNOX_CHECK = 'setup.fortnox_check';

    public const STOREFRONT_ABILITY = 'prices:read';

    public const STOREFRONT_TOKEN_NAME = 'WooCommerce storefront';

    public const DONE = 'done';

    public const TODO = 'todo';

    public const PROBLEM = 'problem';

    /** @return list<array{key: string, title: string, state: string}&array<string, mixed>> */
    public function steps(): array
    {
        return [
            $this->wooStep(),
            $this->webhookStep(),
            $this->fortnoxStep(),
            $this->bufferStep(),
            $this->tokenStep(),
        ];
    }

    public function isComplete(): bool
    {
        return collect($this->steps())->every(fn (array $step) => $step['state'] === self::DONE);
    }

    // --- Checks (these call the outside world) ---------------------------------------------

    /** The WooCommerce client for setup buttons: short timeout, no retries. */
    public static function interactiveWoo(): WooCommerceClient
    {
        return app(WooCommerceClient::class)->forInteractiveUse();
    }

    /** Calls WooCommerce: counts products, reads the currency, looks for our webhooks. */
    public function checkWoo(): array
    {
        $result = ['ok' => false, 'error' => null, 'products' => null, 'currency' => null, 'missing_webhooks' => null, 'checked_at' => now()->toIso8601String()];

        try {
            $woo = self::interactiveWoo();
            $result['products'] = $woo->total('products');
            $currency = collect($woo->get('settings/general'))->firstWhere('id', 'woocommerce_currency');
            $result['currency'] = $currency['value'] ?? null;
            $result['missing_webhooks'] = (new WebhookRegistrar($woo))->missing(WebhookRegistrar::defaultUrl());
            $result['ok'] = true;
        } catch (WooCommerceException $e) {
            $result['error'] = $e->status === 401
                ? 'WooCommerce refused the API key. Check the consumer key and secret, and that the key has Read/Write access.'
                : $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $result['error'] = $e->getMessage();
        } catch (Throwable $e) {
            $result['error'] = 'Could not reach WooCommerce: '.$e->getMessage();
        }

        AppSetting::write(self::WOO_CHECK, $result);

        return $result;
    }

    /** Calls Fortnox: company name plus one record of each resource we use. */
    public function checkFortnox(): array
    {
        try {
            $result = app(LicenceCheck::class)->run();
        } catch (Throwable $e) {
            $result = ['company' => null, 'organisation_number' => null, 'error' => $e->getMessage(), 'checks' => [], 'missing' => []];
        }
        $result['checked_at'] = now()->toIso8601String();

        AppSetting::write(self::FORTNOX_CHECK, $result);

        return $result;
    }

    /** Forget the last Fortnox check, e.g. after logging in to another Fortnox company. */
    public function forgetFortnoxCheck(): void
    {
        AppSetting::query()->whereKey(self::FORTNOX_CHECK)->delete();
    }

    /**
     * After the webhooks are registered, record that none are missing so the step turns green
     * without another round trip to WooCommerce.
     */
    public function markWebhooksRegistered(): void
    {
        $check = AppSetting::read(self::WOO_CHECK);
        if (is_array($check)) {
            AppSetting::write(self::WOO_CHECK, array_merge($check, ['missing_webhooks' => []]));
        }
    }

    // --- Steps -----------------------------------------------------------------------------

    private function wooStep(): array
    {
        $configured = $this->wooConfigured();
        $check = $this->lastCheck(self::WOO_CHECK);

        $state = match (true) {
            ! $configured => self::PROBLEM,
            $check === null => self::TODO,
            $check['ok'] => self::DONE,
            default => self::PROBLEM,
        };

        return [
            'key' => 'woo',
            'title' => 'Connect the WooCommerce store',
            'state' => $state,
            'configured' => $configured,
            'store_url' => (string) config('services.woocommerce.base_url'),
            'check' => $check,
        ];
    }

    private function webhookStep(): array
    {
        $check = $this->lastCheck(self::WOO_CHECK);
        $missing = $check['missing_webhooks'] ?? null;

        $state = match (true) {
            $check === null || ! $check['ok'] || $missing === null => self::TODO,
            $missing === [] => self::DONE,
            default => self::TODO,
        };

        return [
            'key' => 'webhooks',
            'title' => 'Send new orders and product changes here',
            'state' => $state,
            'ready' => $check !== null && $check['ok'],
            'secret_set' => (string) config('services.woocommerce.webhook_secret') !== '',
            'delivery_url' => WebhookRegistrar::defaultUrl(),
            'topics' => WebhookRegistrar::TOPICS,
            'missing' => $missing,
        ];
    }

    private function fortnoxStep(): array
    {
        $usesFortnox = config('erp.driver') === FortnoxClient::DRIVER;
        $fortnox = app(FortnoxClient::class);
        $configured = $usesFortnox && $fortnox->isConfigured();
        $connection = $configured ? $fortnox->connection() : null;
        $check = $connection ? $this->lastCheck(self::FORTNOX_CHECK) : null;

        $state = match (true) {
            ! $usesFortnox, ! $configured => self::PROBLEM,
            $connection === null, $check === null => self::TODO,
            LicenceCheck::passed($check) => self::DONE,
            default => self::PROBLEM,
        };

        return [
            'key' => 'fortnox',
            'title' => 'Log in to Fortnox',
            'state' => $state,
            'uses_fortnox' => $usesFortnox,
            'configured' => $configured,
            'connected_at' => $connection?->connected_at,
            'redirect_uri' => (string) config('erp.fortnox.redirect_uri'),
            'check' => $check,
        ];
    }

    private function bufferStep(): array
    {
        return [
            'key' => 'buffer',
            'title' => 'Choose a stock buffer',
            'state' => StockBuffer::isSetInApp() ? self::DONE : self::TODO,
            'value' => StockBuffer::default(),
            'max' => StockBuffer::MAX,
        ];
    }

    private function tokenStep(): array
    {
        $tokens = $this->storefrontTokens();

        return [
            'key' => 'token',
            'title' => 'Connect the B2B Prices plugin',
            'state' => $tokens > 0 ? self::DONE : self::TODO,
            'tokens' => $tokens,
            // Same localhost -> 127.0.0.1 swap as the webhook URL (Windows may resolve localhost to IPv6).
            'api_url' => preg_replace('#^(https?://)localhost\b#', '${1}127.0.0.1', rtrim((string) config('app.url'), '/')),
        ];
    }

    // --- Helpers ---------------------------------------------------------------------------

    private function wooConfigured(): bool
    {
        return (string) config('services.woocommerce.base_url') !== ''
            && (string) config('services.woocommerce.consumer_key') !== ''
            && (string) config('services.woocommerce.consumer_secret') !== '';
    }

    /** The stored result with checked_at as a Carbon instance, or null if never checked. */
    private function lastCheck(string $key): ?array
    {
        $check = AppSetting::read($key);
        if (! is_array($check)) {
            return null;
        }
        $check['checked_at'] = isset($check['checked_at']) ? Carbon::parse($check['checked_at']) : null;

        return $check;
    }

    /** Tokens of the API service user that may read storefront prices. */
    private function storefrontTokens(): int
    {
        $owner = User::where('email', User::API_SERVICE_EMAIL)->first();
        if ($owner === null) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->whereMorphedTo('tokenable', $owner)
            ->get(['abilities'])
            ->filter(fn (PersonalAccessToken $t) => in_array('*', $t->abilities ?? [], true)
                || in_array(self::STOREFRONT_ABILITY, $t->abilities ?? [], true))
            ->count();
    }
}
