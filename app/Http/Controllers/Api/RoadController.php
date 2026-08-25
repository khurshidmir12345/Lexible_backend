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

        return [
            'paths' => $this->paths($request),
            'nodes' => $nodes->map(fn (Category $c) => [
                'id' => $c->id,
                // Inside a teacher's path the stage keeps its own numbering,
                // so a class talks about "3-bosqich" and everyone means the
                // same lesson regardless of what else is on their map.
                'position' => $c->pathStage?->position ?? $c->position,
                'title' => $c->title,
                'type' => $c->type,
                'status' => $c->status,
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

            $paths[] = [
                'id' => (string) $group->id,
                'title' => $group->title,
                'kind' => 'group',
                'subtitle' => $group->subtitle,
                'teacher' => $group->teacher?->full_name,
                'badge' => $group->badge,
            ];
        }

        return $paths;
    }
}
