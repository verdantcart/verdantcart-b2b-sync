<?php

namespace App\Erp\Fortnox\Events;

use App\Models\AppSetting;

/**
 * What the event listener is doing, for the dashboard and the setup page:
 * connected or reconnecting, since when, and the last event and error.
 */
class ListenerStatus
{
    public const SETTING = 'fortnox.events.status';

    public const CONNECTED = 'connected';

    public const RECONNECTING = 'reconnecting';

    public const FAILED = 'failed';

    public static function get(): ?array
    {
        $status = AppSetting::read(self::SETTING);

        return is_array($status) ? $status : null;
    }

    public static function connected(int $tenantId, array $topics): void
    {
        self::merge(['state' => self::CONNECTED, 'since' => now()->toIso8601String(), 'tenant_id' => $tenantId, 'topics' => $topics, 'error' => null]);
    }

    public static function event(FortnoxEvent $event): void
    {
        self::merge(['last_event_at' => now()->toIso8601String(), 'last_event' => "{$event->type} {$event->entityId}"]);
    }

    public static function reconnecting(string $error): void
    {
        self::merge(['state' => self::RECONNECTING, 'since' => now()->toIso8601String(), 'error' => $error]);
    }

    public static function failed(string $error): void
    {
        self::merge(['state' => self::FAILED, 'since' => now()->toIso8601String(), 'error' => $error]);
    }

    private static function merge(array $values): void
    {
        AppSetting::write(self::SETTING, array_merge(self::get() ?? [], $values));
    }
}
