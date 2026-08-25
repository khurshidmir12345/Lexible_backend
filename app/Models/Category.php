<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unlock_date' => 'date',
            'practiced' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function pathStage(): BelongsTo
    {
        return $this->belongsTo(PathStage::class);
    }

    /** A stage handed down by a teacher; the player cannot edit its words. */
    public function isFromGroup(): bool
    {
        return $this->group_id !== null;
    }

    public function words(): BelongsToMany
    {
        return $this->belongsToMany(Word::class)
            ->withPivot('sort_order')
            ->orderBy('category_word.sort_order');
    }

    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /** Seasonal decoration on the map is driven by the node's date. */
    public function season(): string
    {
        $month = (int) ($this->unlock_date?->format('n') ?? now()->format('n'));

        return match (true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'autumn',
            default => 'winter',
        };
    }

    public function refreshWordsCount(): void
    {
        $this->updateQuietly(['words_count' => $this->words()->count()]);
    }
}
