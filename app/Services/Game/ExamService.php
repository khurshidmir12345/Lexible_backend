<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\Word;
use Illuminate\Support\Collection;

/**
 * Exam checkpoints.
 *
 * An exam stage holds no words. It samples from everything the player has
 * covered before it on the same path, so passing means the earlier lessons
 * actually stuck rather than that one more list was memorised.
 */
class ExamService
{
    /** Every word from the stages leading up to this exam. */
    public function pool(Category $exam): Collection
    {
        $earlier = Category::where('user_id', $exam->user_id)
            ->where('position', '<', $exam->position)
            ->where('type', 'normal')
            // A group exam covers that group's lessons; a personal one, the
            // player's own.
            ->when($exam->group_id, fn ($q) => $q->where('group_id', $exam->group_id))
            ->when(! $exam->group_id, fn ($q) => $q->whereNull('group_id'))
            ->pluck('id');

        if ($earlier->isEmpty()) {
            return collect();
        }

        return Word::query()
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $earlier))
            ->usable($exam->user->native_lang)
            ->get();
    }

    /**
     * An exam is a fixed number of questions, not a fixed number of words: each
     * sampled word is asked once, in one exercise, so the count is exact.
     *
     * @return array{slices: array<array{type: string, words: Collection}>, types: array}
     */
    public function plan(Category $exam): array
    {
        $types = config('game.exam.types');
        $words = $this->pool($exam)->shuffle()->take(config('game.exam.questions'))->values();

        if ($words->isEmpty()) {
            return ['slices' => [], 'types' => $types];
        }

        // Spread the words evenly over the exercises so no one skill decides
        // the result on its own.
        $chunks = $words->chunk((int) ceil($words->count() / count($types)))->values();

        $slices = $chunks
            ->map(fn (Collection $chunk, int $i) => ['type' => $types[$i] ?? end($types), 'words' => $chunk])
            ->all();

        return ['slices' => $slices, 'types' => $types];
    }

    /** What the confirmation sheet needs before the player commits. */
    public function briefing(Category $exam): array
    {
        $pool = $this->pool($exam);
        $covered = Category::where('user_id', $exam->user_id)
            ->where('position', '<', $exam->position)
            ->where('type', 'normal')
            ->when($exam->group_id, fn ($q) => $q->where('group_id', $exam->group_id))
            ->when(! $exam->group_id, fn ($q) => $q->whereNull('group_id'))
            ->orderBy('position')
            ->get();

        $questions = min(config('game.exam.questions'), $pool->count());

        return [
            'questions' => $questions,
            'pass_mark' => config('game.exam.pass_mark'),
            'pool' => $pool->count(),
            'ready' => $questions > 0,
            'covers' => $covered->isEmpty()
                ? null
                : "{$covered->first()->position}–{$covered->last()->position} bosqichlardagi soʼzlardan",
        ];
    }

    public function passed(int $accuracy): bool
    {
        return $accuracy >= config('game.exam.pass_mark');
    }
}
