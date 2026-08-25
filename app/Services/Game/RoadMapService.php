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

    /** Called when a category's words are all practised well enough. */
    public function complete(Category $category): ?Category
    {
        $category->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);

        $next = Category::where('user_id', $category->user_id)
            ->where('position', $category->position + 1)
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

        $last = $user->categories()->max('position') ?? 0;
        $furthestOpen = $user->categories()->where('status', '!=', 'locked')->max('position') ?? 1;

        for ($position = $last + 1; $position <= $furthestOpen + $lookahead; $position++) {
            Category::create([
                'user_id' => $user->id,
                'position' => $position,
                'type' => $position % $examEvery === 0 ? 'exam' : 'normal',
                'status' => 'locked',
                'unlock_date' => $this->dateFor($user, $position),
            ]);
        }
    }

    /** Progress is how far the category's words are from being learned. */
    public function refreshProgress(Category $category): void
    {
        $wordIds = $category->words()->pluck('words.id');

        if ($wordIds->isEmpty()) {
            $category->update(['progress' => 0, 'words_count' => 0]);

            return;
        }

        $average = \App\Models\WordProgress::where('user_id', $category->user_id)
            ->whereIn('word_id', $wordIds)
            ->avg('overall') ?? 0;

        $category->update([
            'progress' => (int) round($average),
            'words_count' => $wordIds->count(),
        ]);
    }
}
