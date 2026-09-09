<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordProgress extends Model
{
    /**
     * The five scored exercises, in the order the UI lists them. The flashcard
     * is a game too, but it has no right answer — the player just flips it —
     * so it never moves a word's mastery.
     */
    public const DIMENSIONS = ['uz2en', 'en2uz', 'spell', 'image', 'match'];

    protected $table = 'word_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_learned' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_practiced_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function word(): BelongsTo
    {
        return $this->belongsTo(Word::class);
    }

    public function mastery(): array
    {
        return collect(self::DIMENSIONS)
            ->mapWithKeys(fn ($d) => [$d => (int) $this->{'m_'.$d}])
            ->all();
    }

    /** Recompute the percent shown on the word row and the learned flag. */
    public function recalculate(): void
    {
        // A word climbs in five steps of 20%: each exercise answered right is
        // one step, and 100% means it has been found in all five. Types not
        // played yet count as steps not taken, not as "no data".
        $this->overall = (int) round(array_sum($this->mastery()) / count(self::DIMENSIONS));
        $this->is_learned = $this->overall >= config('game.mastery.learned_at', 70);
    }
}
