<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The safety buffer a SKU gets until it has one of its own.
 * Set in the setup guide; falls back to B2B_STOCK_BUFFER from .env.
 */
class StockBuffer
{
    public const SETTING = 'inventory.default_buffer';

    public const MAX = 100000;

    public static function default(): int
    {
        return (int) AppSetting::read(self::SETTING, (int) config('b2bsync.inventory.default_buffer', 0));
    }

    public static function isSetInApp(): bool
    {
        return AppSetting::read(self::SETTING) !== null;
    }
}
