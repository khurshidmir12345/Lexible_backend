<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\TestAnswer;
use App\Services\Teaching\GroupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupController extends Controller
{
    public function __construct(protected GroupService $groups) {}

    public function index(Request $request): array
    {
        $this->authorizeTeacher($request);

        return [
            'groups' => $request->user()->groups()->with('path')->get()->map(fn (Group $group) => [
                'id' => $group->id,
                'badge' => $group->badge,
                'title' => $group->title,
                'subtitle' => $group->subtitle,
                'code' => $group->code,
                'members' => $group->members_count,
                'pending' => $group->pending()->count(),
                'path' => $group->path?->title,
            ]),
        ];
    }

    public function store(Request $request): array
    {
        $this->authorizeTeacher($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:80'],
            'badge' => ['nullable', 'string', 'max:6'],
            'path_id' => ['nullable', 'integer', 'exists:paths,id'],
        ]);

        $group = $this->groups->create($request->user(), $data);

        return ['group' => ['id' => $group->id, 'code' => $group->code, 'title' => $group->title]];
    }

    public function show(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $stageId = $request->integer('stage') ?: null;

        return [
            'group' => [
                'id' => $group->id,
                'badge' => $group->badge,
                'title' => $group->title,
                'subtitle' => $group->subtitle,
                'code' => $group->code,
                'members' => $group->members_count,
                'path' => $group->path ? [
                    'id' => $group->path->id,
                    'title' => $group->path->title,
                    'subtitle' => $group->path->subtitle,
                    'stages' => $group->path->stages->map(fn ($stage) => [
                        'id' => $stage->id,
                        'position' => $stage->position,
                        'title' => $stage->title,
                        'words_count' => $stage->words_count,
                    ]),
                ] : null,
            ],
            'pending' => $group->pending()->with('user')->get()->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'name' => $m->user->full_name,
                'initial' => $m->user->initial,
                'telegram_id' => $m->user->telegram_id,
            ]),
            'leaderboard' => $this->groups->leaderboard($group, $stageId),
        ];
    }

    /** Attaching or swapping the path re-materialises it for every student. */
    public function attachPath(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $data = $request->validate([
            'path_id' => ['required', 'integer', 'exists:paths,id'],
        ]);

        abort_unless(
            $request->user()->paths()->whereKey($data['path_id'])->exists(),
            Response::HTTP_FORBIDDEN,
        );

        $group->update($data);
        $group->refresh();

        foreach ($group->students()->get() as $student) {
            $this->groups->materialise($group, $student);
        }

        return ['group' => ['id' => $group->id, 'path' => $group->path?->title]];
    }

    public function approve(Request $request, GroupMember $member): array
    {
        $this->authorizeOwner($request, $member->group);

        $this->groups->approve($member);

        return ['status' => 'active'];
    }

    public function remove(Request $request, GroupMember $member): array
    {
        $this->authorizeOwner($request, $member->group);

        $this->groups->remove($member);

        return ['status' => 'removed'];
    }

    /** The teacher's home screen. */
    public function dashboard(Request $request): array
    {
        $this->authorizeTeacher($request);

        $groups = $request->user()->groups()->with('path')->get();
        $studentIds = GroupMember::whereIn('group_id', $groups->pluck('id'))
            ->where('status', 'active')
            ->pluck('user_id')
            ->unique();

        $activeToday = TestAnswer::whereIn('user_id', $studentIds)
            ->whereDate('created_at', today())
            ->distinct()
            ->count('user_id');

        $weekStart = today()->startOfWeek();
        $perDay = TestAnswer::whereIn('user_id', $studentIds)
            ->where('created_at', '>=', $weekStart)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $week = collect(range(0, 6))
            ->map(fn (int $offset) => (int) ($perDay[$weekStart->copy()->addDays($offset)->toDateString()] ?? 0));

        return [
            'name' => $request->user()->first_name ?: $request->user()->full_name,
            'students' => $studentIds->count(),
            'groups' => $groups->count(),
            'active_today' => $activeToday,
            'week' => $week->all(),
            'week_total' => $week->sum(),
            'pending' => GroupMember::whereIn('group_id', $groups->pluck('id'))
                ->where('status', 'pending')
                ->count(),
            'top_groups' => $groups->map(fn (Group $group) => [
                'id' => $group->id,
                'badge' => $group->badge,
                'title' => $group->title,
                'members' => $group->members_count,
                'score' => collect($this->groups->leaderboard($group))->avg('score') ?? 0,
            ])->sortByDesc('score')->take(3)->map(fn ($row) => $row + ['score' => (int) round($row['score'])])->values(),
        ];
    }

    protected function authorizeTeacher(Request $request): void
    {
        abort_unless($request->user()->isTeacher(), Response::HTTP_FORBIDDEN, 'Bu boʼlim ustozlar uchun.');
    }

    protected function authorizeOwner(Request $request, Group $group): void
    {
        $this->authorizeTeacher($request);
        abort_unless($group->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
