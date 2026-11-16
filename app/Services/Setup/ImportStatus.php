<?php

namespace App\Services\Setup;

use App\Models\AppSetting;

/**
 * Where the setup guide's background imports are: queued → running → done | failed.
 * Kept in app_settings so the page can show progress and the last result.
 */
class ImportStatus
{
    public const PRODUCTS = 'setup.import.products';

    public const CUSTOMERS = 'setup.import.customers';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public static function get(string $key): ?array
    {
        $value = AppSetting::read($key);

        return is_array($value) ? $value : null;
    }

    public static function queued(string $key): void
    {
        AppSetting::write($key, ['state' => self::QUEUED, 'message' => 'Waiting for the queue worker', 'queued_at' => now()->toIso8601String()]);
    }

    public static function running(string $key, string $message): void
    {
        AppSetting::write($key, array_merge(self::get($key) ?? [], ['state' => self::RUNNING, 'message' => $message, 'started_at' => self::get($key)['started_at'] ?? now()->toIso8601String()]));
    }

    public static function done(string $key, array $result): void
    {
        AppSetting::write($key, ['state' => self::DONE, 'result' => $result, 'finished_at' => now()->toIso8601String()]);
    }

    public static function failed(string $key, string $error): void
    {
        AppSetting::write($key, ['state' => self::FAILED, 'error' => $error, 'finished_at' => now()->toIso8601String()]);
    }

    public static function isBusy(?array $status): bool
    {
        return in_array($status['state'] ?? null, [self::QUEUED, self::RUNNING], true);
    }

    /** Update one field of a finished result, e.g. after products were linked by hand. */
    public static function patchResult(string $key, callable $change): void
    {
        $status = self::get($key);
        if (($status['state'] ?? null) === self::DONE) {
            $status['result'] = $change($status['result']);
            AppSetting::write($key, $status);
        }
    }
}
