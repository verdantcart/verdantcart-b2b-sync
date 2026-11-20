<?php

namespace App\Erp\Fortnox\Events;

use App\Models\AppSetting;

/**
 * The last event offset handled per topic. Sent back when subscribing, so after a restart
 * Fortnox replays what was missed (it keeps events for 14 days).
 */
class OffsetStore
{
    public const SETTING = 'fortnox.events.offsets';

    /** @return array<string, string> topic => offset */
    public function all(): array
    {
        $offsets = AppSetting::read(self::SETTING, []);

        return is_array($offsets) ? $offsets : [];
    }

    public function save(string $topic, string $offset): void
    {
        AppSetting::write(self::SETTING, array_merge($this->all(), [$topic => $offset]));
    }

    public function forget(): void
    {
        AppSetting::query()->whereKey(self::SETTING)->delete();
    }
}
