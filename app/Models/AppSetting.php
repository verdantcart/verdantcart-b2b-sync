<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings changed in the app. Values are JSON, so arrays round-trip.
 */
#[Table('app_settings')]
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::query()->find($key);

        return $row === null || $row->value === null ? $default : $row->value;
    }

    public static function write(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
