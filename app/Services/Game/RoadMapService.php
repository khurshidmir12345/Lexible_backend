<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds and advances the road map.
 *
 * The map always shows more nodes than the player has reached, so it reads as
 * a journey rather than a to-do list. Node 1 starts unlocked; finishing a node
 * unlocks the next one and extends the map so the lookahead stays constant.
 */
class RoadMapService
{
    public function __construct(protected NotificationService $notifications) {}

    public function forUser(User $user): Collection
    {
        if ($user->categories()->count() === 0) {
            $this->createInitialNodes($user);
        }

        return $user->categories()->get();
    }

    protected function createInitialNodes(User $user): void
    {
        $count = config('game.road.initial_nodes');
        $examEvery = config('game.road.exam_every');

        for ($position = 1; $position <= $count; $position++) {
            Category::create([
                'user_id' => $user->id,
                'position' => $position,
                'type' => $position % $examEvery === 0 ? 'exam' : 'normal',
                'status' => $position === 1 ? 'in_progress' : 'locked',
                'unlock_date' => $this->dateFor($user, $position),
            ]);
        }
    }

    /**
     * Nodes are spaced by how much the player intends to study: a 5-words-a-day
     * goal spreads the map further into the future than a 20-words-a-day one.
     */
    protected function dateFor(User $user, int $position): Carbon
    {
        $daysPerNode = max(7, (int) round(60 / max($user->daily_goal, 1)) * 7);

        return now()->addDays(($position - 1) * $daysPerNode)->startOfDay();
    }

    /**
     * Positions are unique per player and shared with the class stages a
     * teacher hands down, which get appended after whatever is on the map. The
     * personal road is therefore the personal nodes in position order, and
     * everything that counts nodes — the exam rhythm, the lookahead, the
     * numbers on the map — must count that road, not raw positions.
     */
    protected function personal(User $user)
    {
        return $user->categories()->whereNull('group_id')->orderBy('position');
    }

    /** Which road a node belongs to: the personal one, or one class's. */
    protected function sameRoad(Category $category)
    {
        return Category::where('user_id', $category->user_id)
            ->when($category->group_id, fn ($q) => $q->where('group_id', $category->group_id))
            ->when(! $category->group_id, fn ($q) => $q->whereNull('group_id'));
    }

    /**
     * The number each node wears on the map, keyed by category id. Personal
     * nodes count 1, 2, 3… along their own road; class stages keep the number
     * the teacher gave them, so the whole class means the same lesson by it.
     *
     * @return array<int, int>
     */
    public function numbering(User $user): array
    {
        $numbers = [];
        $ordinal = 0;
        foreach ($user->categories()->with('pathStage')->orderBy('position')->get() as $category) {
            $numbers[$category->id] = $category->group_id
                ? ($category->pathStage?->position ?? $category->position)
                : ++$ordinal;
        }

        return $numbers;
    }

    /** Called when a category's words are all practised well enough. */
    public function complete(Category $category): ?Category
    {
        $category->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);

        // The next node on the same road — never a class stage that happens
        // to hold the next position number.
        $next = $this->sameRoad($category)
            ->where('position', '>', $category->position)
            ->orderBy('position')
            ->first();

        if ($next && $next->status === 'locked') {
            $next->update(['status' => 'in_progress']);
            $this->notifications->stageUnlocked($category->user_id, $next->title ?? "{$next->position}-bosqich");
        }

        $this->extend($category->user);

        return $next;
    }

    /** Keep a fixed number of locked nodes past the furthest unlocked one. */
    public function extend(User $user): void
    {
        $lookahead = config('game.road.lookahead');
        $examEvery = config('game.road.exam_every');

        $road = $this->personal($user)->get();
        $count = $road->count();
        $furthestOpen = $road->where('status', '!=', 'locked')->keys()->last() ?? 0;   // zero-based

        // Positions are unique across the player's map, so a new node goes
        // after everything, but it is the personal count that says whether
        // it is an exam.
        $position = (int) ($user->categories()->max('position') ?? 0);

        for ($ordinal = $count + 1; $ordinal <= $furthestOpen + 1 + $lookahead; $ordinal++) {
            Category::create([
                'user_id' => $user->id,
                'position' => ++$position,
                'type' => $ordinal % $examEvery === 0 ? 'exam' : 'normal',
                'status' => 'locked',
                'unlock_date' => $this->dateFor($user, $ordinal),
            ]);
        }
    }

    /**
     * Straightens a road that was extended while class stages sat between its
     * nodes: the exam rhythm is re-read from the personal count. Only untouched
     * nodes are retyped; one with words or progress is left as it is.
     *
     * @return array{fixed: int, skipped: int}
     */
    public function repair(User $user): array
    {
        $examEvery = config('game.road.exam_every');
        $fixed = 0;
        $skipped = 0;

        foreach ($this->personal($user)->get()->values() as $i => $category) {
            $type = ($i + 1) % $examEvery === 0 ? 'exam' : 'normal';

            if ($category->type === $type) {
                continue;
            }

            if ($category->status !== 'locked' || $category->words_count > 0) {
                $skipped++;

                continue;
            }

            $category->update(['type' => $type, 'title' => null]);
            $fixed++;
        }

        return ['fixed' => $fixed, 'skipped' => $skipped];
    }

    /** Progress is how far the category's words are from being learned. */
    public function refreshProgress(Category $category): void
    {
        $wordIds = $category->words()->pluck('words.id');

        if ($wordIds->isEmpty()) {
            $category->update(['progress' => 0, 'words_count' => 0]);

            return;
        }

        // Every word in the stage counts, practised or not: a stage is as
        // learned as its words are, and an untouched word is a word at 0%.
        $sum = (int) \App\Models\WordProgress::where('user_id', $category->user_id)
            ->whereIn('word_id', $wordIds)
            ->sum('overall');

        $category->update([
            'progress' => (int) round($sum / $wordIds->count()),
            'words_count' => $wordIds->count(),
        ]);
    }
}
