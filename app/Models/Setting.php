<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('settings.all'));
        static::deleted(fn () => Cache::forget('settings.all'));
    }

    public static function all_cached(): array
    {
        return Cache::rememberForever('settings.all', fn () => static::query()->pluck('value', 'key')->toArray());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::all_cached()[$key] ?? null;

        // JSON-cast scalars come back wrapped; unwrap the common single-value case.
        return $value === null ? $default : $value;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }
}
