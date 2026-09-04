<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The offline matcher's shortlist of icons for one word, best first. */
class IconCandidate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['slugs' => 'array'];
    }

    /** @return list<string> */
    public static function slugsFor(string $normalized): array
    {
        return static::query()->where('normalized', $normalized)->value('slugs') ?? [];
    }
}
