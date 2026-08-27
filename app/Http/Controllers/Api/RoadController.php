<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\GroupMember;
use App\Services\Game\RoadMapService;
use Illuminate\Http\Request;

class RoadController extends Controller
{
    /**
     * The map, plus the paths it can be filtered by.
     *
     * A player always has their own path; each group they belong to adds
     * another, drawn from the stages their teacher handed down.
     */
    public function __invoke(Request $request, RoadMapService $road): array
    {
        $user = $request->user();
        $nodes = $road->forUser($user)->load(['group.teacher', 'pathStage']);

        $paths = $this->paths($request);

        // OQ-03: on a "student pays" class the stages stay visible but shut
        // until the month is paid for, so the player sees what is waiting.
        $unpaid = collect($paths)->where('payment_required', true)->pluck('id')->all();

        return [
            'paths' => $paths,
            'nodes' => $nodes->map(fn (Category $c) => [
                'id' => $c->id,
                // Inside a teacher's path the stage keeps its own numbering,
                // so a class talks about "3-bosqich" and everyone means the
                // same lesson regardless of what else is on their map.
                'position' => $c->pathStage?->position ?? $c->position,
                'title' => $c->title,
                'type' => $c->type,
                'status' => in_array((string) $c->group_id, $unpaid, true) ? 'locked' : $c->status,
                'lock_reason' => in_array((string) $c->group_id, $unpaid, true) ? 'payment' : null,
                'progress' => $c->progress,
                'words_count' => $c->words_count,
                'date' => $c->unlock_date?->toDateString(),
                'season' => $c->season(),
                'practiced' => $c->practiced,
                // Group stages are read-only for the player and drawn in gold.
                'path' => $c->group_id ? (string) $c->group_id : 'personal',
                'from_group' => $c->isFromGroup(),
            ])->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function paths(Request $request): array
    {
        $paths = [[
            'id' => 'personal',
            'title' => 'Yoʼl',
            'kind' => 'personal',
            'subtitle' => null,
            'teacher' => null,
        ]];

        $memberships = GroupMember::with(['group.teacher'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->get();

        foreach ($memberships as $membership) {
            $group = $membership->group;
            $teacher = $group->teacher;

            // Two ways a class is funded. On the teacher's own plan the
            // student never sees a price; on the other the path stays shut
            // until their month is paid.
            $studentPays = $teacher?->billing_mode === 'student';
            $paid = $membership->paid_until && $membership->paid_until->isFuture();

            $paths[] = [
                'id' => (string) $group->id,
                'title' => $group->title,
                'kind' => 'group',
                'subtitle' => $group->subtitle,
                'teacher' => $teacher?->full_name,
                'badge' => $group->badge,
                'payment_required' => $studentPays && ! $paid,
                'price' => $studentPays ? (int) config('game.teaching.student_price') : null,
                'paid_until' => $membership->paid_until?->toDateString(),
            ];
        }

        return $paths;
    }
}
