<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Teaching\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class GroupJoinController extends Controller
{
    public function __construct(protected GroupService $groups) {}

    /**
     * A student types whatever their teacher gave them.
     *
     * That is one of two things — a group code off the whiteboard
     * ("5A-KITOB") or the teacher's own ID from their profile ("TCHR-2381").
     * Onboarding asks for the second and the class list hands out the first,
     * so both have to land here or one of them is a dead end.
     */
    public function join(Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            // Set when the student is answering the "which class?" question
            // this endpoint asked on a previous call.
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);

        $code = $this->normalise($data['code']);

        if (! empty($data['group_id'])) {
            $group = Group::where('id', $data['group_id'])->where('is_active', true)->first();
            abort_unless($group, Response::HTTP_NOT_FOUND, 'Guruh topilmadi.');

            return $this->request($group, $request->user());
        }

        if ($group = Group::where('code', $code)->where('is_active', true)->first()) {
            return $this->request($group, $request->user());
        }

        // Not a group code — try it as a teacher ID.
        $teacher = User::where('teacher_ref', $code)->where('role', 'teacher')->first();

        abort_unless($teacher, Response::HTTP_NOT_FOUND,
            'Bunday kod topilmadi. Guruh kodi (5A-KITOB) yoki ustoz ID (TCHR-1234) ni tekshiring.');

        $classes = $teacher->groups()->where('is_active', true)->get();

        abort_if($classes->isEmpty(), Response::HTTP_NOT_FOUND,
            $teacher->full_name.' hali guruh yaratmagan. Ustozingizdan guruh kodini soʼrang.');

        if ($classes->count() === 1) {
            return $this->request($classes->first(), $request->user());
        }

        // Several classes under one ID: the student has to say which.
        return [
            'status' => 'choose',
            'teacher' => [
                'name' => $teacher->full_name,
                'initial' => $teacher->initial,
                'ref' => $teacher->teacher_ref,
            ],
            'groups' => $classes->map(fn (Group $group) => [
                'id' => $group->id,
                'badge' => $group->badge,
                'title' => $group->title,
                'subtitle' => $group->subtitle,
                'members' => $group->members_count,
            ])->values(),
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
                    'teacher_ref' => $group->teacher->teacher_ref,
                    'status' => $membership->status,
                    'path' => $group->path?->title,
                    'stages' => $group->path?->stages_count ?? 0,
                    'members' => $group->members_count,
                    'requested_at' => $membership->created_at?->toIso8601String(),
                    'my_rank' => $me['rank'] ?? null,
                    'my_score' => $me['score'] ?? 0,
                    'leaderboard' => collect($board)->take(10)->values(),
                ];
            })->values(),
        ];
    }

    /** A student who changed their mind, or was refused, can withdraw. */
    public function leave(Request $request, Group $group): array
    {
        $membership = GroupMember::where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->groups->remove($membership);

        return ['status' => 'left'];
    }

    protected function request(Group $group, User $student): array
    {
        $membership = $this->groups->requestJoin($group, $student);

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

    /** "tchr 2381", "5a kitob" and "5A-KITOB" all mean what they look like. */
    protected function normalise(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/\s+/', '-', $code);

        return trim((string) $code, '-');
    }
}
