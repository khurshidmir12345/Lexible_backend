<?php

namespace App\Services\Teaching;

use App\Models\Category;
use App\Models\Group;
use App\Models\PathStage;
use App\Models\User;
use App\Models\WordProgress;
use Illuminate\Support\Collection;

/**
 * What a teacher actually looks at: how far the class has got along the path
 * (UT-04b) and, inside one stage, who is stuck on what (UT-05).
 */
class ClassReportService
{
    /** Uzbek labels for the six exercises, as they appear on the weak chips. */
    public const EXERCISE_LABELS = [
        'card' => 'Karta',
        'uz2en' => 'U→E',
        'en2uz' => 'E→U',
        'spell' => 'Imlo',
        'image' => 'Rasm',
        'match' => 'Juftlash',
    ];

    /** The group's path with a class average on every stage — UT-04b. */
    public function road(Group $group): array
    {
        $group->loadMissing('path.stages');

        $students = $group->students()->pluck('users.id');
        $stages = $group->path?->stages ?? collect();

        if ($stages->isEmpty()) {
            return ['stages' => [], 'average' => 0];
        }

        // One query for the whole class rather than one per stage per student.
        $byStage = Category::whereIn('user_id', $students)
            ->where('group_id', $group->id)
            ->whereIn('path_stage_id', $stages->pluck('id'))
            ->get()
            ->groupBy('path_stage_id');

        $rows = $stages->map(function (PathStage $stage) use ($byStage, $students) {
            $categories = $byStage->get($stage->id) ?? collect();
            $started = $categories->where('status', '!=', 'locked')->count();

            return [
                'id' => $stage->id,
                'position' => $stage->position,
                'title' => $stage->title,
                'type' => $stage->type,
                'words' => $stage->words_count,
                'average' => $categories->isEmpty() ? 0 : (int) round($categories->avg('progress')),
                'started' => $started,
                'students' => $students->count(),
                // Mirrors the student map: green when the class is through it,
                // blue while they are on it, grey before anyone has opened it.
                'status' => match (true) {
                    $started === 0 => 'locked',
                    $categories->avg('progress') >= config('game.mastery.learned_at', 70) => 'completed',
                    default => 'in_progress',
                },
            ];
        })->values()->all();

        $overall = collect($rows)->where('status', '!=', 'locked');

        return [
            'stages' => $rows,
            'average' => $overall->isEmpty() ? 0 : (int) round($overall->avg('average')),
        ];
    }

    /** Every student's standing on one stage, weakest exercises first — UT-05. */
    public function stageResults(Group $group, PathStage $stage): array
    {
        $students = $group->students()->get();
        $wordIds = $stage->words()->pluck('words.id');
        $threshold = (int) config('game.mastery.learned_at', 70);

        $categories = Category::whereIn('user_id', $students->pluck('id'))
            ->where('group_id', $group->id)
            ->where('path_stage_id', $stage->id)
            ->get()
            ->keyBy('user_id');

        $progress = $wordIds->isEmpty()
            ? collect()
            : WordProgress::whereIn('user_id', $students->pluck('id'))
                ->whereIn('word_id', $wordIds)
                ->get()
                ->groupBy('user_id');

        $rows = $students->map(function (User $student) use ($categories, $progress, $threshold) {
            $category = $categories->get($student->id);
            $mine = $progress->get($student->id) ?? collect();

            return [
                'id' => $student->id,
                'name' => $student->full_name,
                'initial' => $student->initial,
                'photo' => $student->photo_url,
                'score' => (int) ($category?->progress ?? 0),
                'started' => (bool) $category && $category->status !== 'locked',
                'idle_days' => $this->idleDays($student),
                'weak' => $this->weakSpots($mine, $threshold),
            ];
        });

        return [
            'stage' => [
                'id' => $stage->id,
                'position' => $stage->position,
                'title' => $stage->title,
                'type' => $stage->type,
                'words' => $stage->words_count,
            ],
            'average' => $rows->isEmpty() ? 0 : (int) round($rows->avg('score')),
            'students' => $rows->sortByDesc('score')->values()->all(),
        ];
    }

    /** The exercises a student is below the pass mark on, weakest first. */
    protected function weakSpots(Collection $progress, int $threshold): array
    {
        if ($progress->isEmpty()) {
            return [];
        }

        return collect(WordProgress::DIMENSIONS)
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => self::EXERCISE_LABELS[$key] ?? $key,
                'score' => (int) round($progress->avg('m_'.$key)),
            ])
            ->filter(fn (array $row) => $row['score'] < $threshold)
            ->sortBy('score')
            ->take(3)
            ->values()
            ->all();
    }

    /** "3 kun kirmagan" — null while the student is keeping up. */
    protected function idleDays(User $student): ?int
    {
        if (! $student->last_practiced_date) {
            return null;
        }

        $days = (int) $student->last_practiced_date->diffInDays(now()->startOfDay());

        return $days >= 2 ? $days : null;
    }
}
