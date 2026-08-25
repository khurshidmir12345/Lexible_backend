<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\Teaching\GroupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupJoinController extends Controller
{
    public function __construct(protected GroupService $groups) {}

    /** A student enters the code their teacher gave them. */
    public function join(Request $request, GroupService $groups): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:24'],
        ]);

        $group = Group::where('code', strtoupper(trim($data['code'])))->first();

        abort_unless($group && $group->is_active, Response::HTTP_NOT_FOUND, 'Bunday kod topilmadi.');

        $membership = $groups->requestJoin($group, $request->user());

        return [
            'status' => $membership->status,
            'group' => [
                'id' => $group->id,
                'badge' => $group->badge,
                'title' => $group->title,
                'subtitle' => $group->subtitle,
                'teacher' => $group->teacher->full_name,
            ],
        ];
    }

    /** The groups a student belongs to, with their standing in each. */
    public function mine(Request $request): array
    {
        $memberships = GroupMember::with(['group.teacher', 'group.path'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending', 'active'])
            ->get();

        return [
            'groups' => $memberships->map(function (GroupMember $membership) use ($request) {
                $group = $membership->group;
                $board = $membership->status === 'active' ? $this->groups->leaderboard($group) : [];
                $me = collect($board)->firstWhere('id', $request->user()->id);

                return [
                    'id' => $group->id,
                    'badge' => $group->badge,
                    'title' => $group->title,
                    'subtitle' => $group->subtitle,
                    'teacher' => $group->teacher->full_name,
                    'status' => $membership->status,
                    'path' => $group->path?->title,
                    'members' => $group->members_count,
                    'my_rank' => $me['rank'] ?? null,
                    'my_score' => $me['score'] ?? 0,
                    'leaderboard' => collect($board)->take(10)->values(),
                ];
            }),
        ];
    }
}
