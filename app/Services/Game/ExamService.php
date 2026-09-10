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
    public function __construct(protected RoadMapService $road) {}

    /**
     * The lessons before this exam on the same road, in road order. A group
     * exam covers that group's lessons; a personal one, the player's own.
     */
    protected function earlier(Category $exam): Collection
    {
        $order = $exam->roadOrder();

        return Category::where('user_id', $exam->user_id)
            ->where('type', 'normal')
            ->when($exam->group_id, fn ($q) => $q->where('group_id', $exam->group_id))
            ->when(! $exam->group_id, fn ($q) => $q->whereNull('group_id'))
            ->with('pathStage')
            ->get()
            ->filter(fn (Category $c) => $c->roadOrder() < $order)
            ->sortBy(fn (Category $c) => $c->roadOrder())
            ->values();
    }

    /** Every word from the stages leading up to this exam. */
    public function pool(Category $exam): Collection
    {
        $earlier = $this->earlier($exam)->pluck('id');

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
     * @return array{slices: array<array{type: string, words: Collection}>, types: array, pool: Collection}
     */
    public function plan(Category $exam): array
    {
        $types = config('game.exam.types');
        $pool = $this->pool($exam);
        $words = $pool->shuffle()->take(config('game.exam.questions'))->values();

        if ($words->isEmpty()) {
            return ['slices' => [], 'types' => $types, 'pool' => $pool];
        }

        // Spread the words evenly over the exercises so no one skill decides
        // the result on its own.
        $chunks = $words->chunk((int) ceil($words->count() / count($types)))->values();

        $slices = $chunks
            ->map(fn (Collection $chunk, int $i) => ['type' => $types[$i] ?? end($types), 'words' => $chunk])
            ->all();

        return ['slices' => $slices, 'types' => $types, 'pool' => $pool];
    }

    /** What the confirmation sheet needs before the player commits. */
    public function briefing(Category $exam): array
    {
        $pool = $this->pool($exam);
        $covered = $this->earlier($exam);

        $questions = min(config('game.exam.questions'), $pool->count());

        // Numbers as the map shows them, not raw positions: a personal road
        // keeps counting from 1 whatever class stages sit between its nodes.
        $numbers = $this->road->numbering($exam->user);
        $first = $numbers[$covered->first()?->id] ?? $covered->first()?->position;
        $last = $numbers[$covered->last()?->id] ?? $covered->last()?->position;

        return [
            'questions' => $questions,
            'pass_mark' => config('game.exam.pass_mark'),
            'pool' => $pool->count(),
            'ready' => $questions > 0,
            'covers' => $covered->isEmpty()
                ? null
                : "{$first}–{$last} bosqichlardagi soʼzlardan",
        ];
    }

    public function passed(int $accuracy): bool
    {
        return $accuracy >= config('game.exam.pass_mark');
    }
}
