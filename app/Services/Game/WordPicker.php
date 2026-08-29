<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\User;
use App\Models\Word;
use App\Models\WordProgress;
use Illuminate\Support\Collection;

/**
 * Chooses the words for a stage.
 *
 * The player says how many words a day they want during onboarding; every
 * stage on the road is that many words, handed to them rather than searched
 * for. Words already seen in an earlier stage are never repeated, so the map
 * keeps moving forward.
 */
class WordPicker
{
    /** @return Collection<int, Word> */
    public function pick(User $user, int $count, array $excludeIds = []): Collection
    {
        $seen = WordProgress::where('user_id', $user->id)->pluck('word_id');

        $base = fn () => Word::teachable($user->native_lang)
            ->whereNotIn('id', $seen->merge($excludeIds)->unique());

        // Words at the player's own level first — that is what the level
        // question in onboarding was for.
        $atLevel = $user->cefr_level
            ? $base()->where('cefr_level', $user->cefr_level)->inRandomOrder()->limit($count)->get()
            : collect();

        if ($atLevel->count() >= $count) {
            return $atLevel;
        }

        // Not enough graded words yet, so top up from the most common ones.
        $filler = $base()
            ->whereNotIn('id', $atLevel->pluck('id'))
            ->orderBy('frequency_rank')
            ->limit(($count - $atLevel->count()) * 4)   // shuffle within a wider band
            ->get()
            ->shuffle()
            ->take($count - $atLevel->count());

        return $atLevel->concat($filler)->values();
    }

    /**
     * Fill an empty stage. Returns how many words were added.
     * Doing nothing when the stage already has words keeps this safe to call
     * on every open.
     */
    public function fill(Category $category): int
    {
        if ($category->words()->exists()) {
            return 0;
        }

        $words = $this->pick($category->user, $category->user->daily_goal);

        if ($words->isEmpty()) {
            return 0;
        }

        $category->words()->attach(
            $words->values()->mapWithKeys(fn (Word $word, int $index) => [
                $word->id => ['sort_order' => $index, 'created_at' => now()],
            ])->all(),
        );

        $category->refreshWordsCount();

        return $words->count();
    }
}
