<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One picture from the 3D icon library.
 *
 * The files live on the public disk as `icons/{size}/{slug}.webp`; the
 * 1024px originals are kept on the private disk under `icons/1024/` and are
 * never served.
 */
class Icon extends Model
{
    /** Sizes rendered on the public disk, smallest first. */
    public const SIZES = [256, 512];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function words(): HasMany
    {
        return $this->hasMany(Word::class);
    }

    /** Public-disk path of one rendered size. */
    public static function pathFor(string $slug, int $size = 256): string
    {
        return "icons/{$size}/{$slug}.webp";
    }

    public function url(int $size = 256): string
    {
        return Storage::disk('public')->url(static::pathFor($this->slug, $size));
    }
}
