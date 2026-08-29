<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Word extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'definition' => 'array',
            'example' => 'array',
            'synonyms' => 'array',
            'pos_all' => 'array',
            'needs_review' => 'boolean',
            'is_active' => 'boolean',
            'is_teachable' => 'boolean',
            'translated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Word $word) {
            $word->normalized = Str::lower(trim($word->word));
        });
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(WordProgress::class);
    }

    /** Words a player can actually be tested on: they need a translation. */
    public function scopeUsable(Builder $query, string $locale = 'uz'): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('translations')
            ->where('translations->'.$locale, '!=', null);
    }

    /**
     * Words worth handing to a learner unasked.
     *
     * The dictionary holds every English word so that search always finds
     * something, but "the" and "of" top the frequency list and make useless
     * flashcards — a stage filled by rank alone would be all grammar.
     */
    public function scopeTeachable(Builder $query, string $locale = 'uz'): Builder
    {
        return $query->usable($locale)->where('is_teachable', true);
    }

    /** Primary translation for a locale, e.g. "chiroyli". */
    public function translation(?string $locale = null): ?string
    {
        return $this->acceptedAnswers($locale)[0] ?? null;
    }

    /** Every accepted spelling for a locale, used when grading typed answers. */
    public function acceptedAnswers(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $value = $this->translations[$locale] ?? [];

        return array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            is_array($value) ? $value : [$value]
        )));
    }

    /** What the card shows: the 3D icon when we have one, else the emoji. */
    public function visual(): array
    {
        return $this->icon_path
            ? ['type' => 'icon', 'value' => asset('storage/'.$this->icon_path)]
            : ['type' => 'emoji', 'value' => $this->emoji ?: '📘'];
    }
}
