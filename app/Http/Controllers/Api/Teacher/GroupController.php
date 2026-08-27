<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\PathStage;
use App\Models\TestAnswer;
use App\Models\User;
use App\Services\Game\NotificationService;
use App\Services\Teaching\ClassReportService;
use App\Services\Teaching\GroupService;
use App\Services\Teaching\PlanService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GroupController extends Controller
{
    public function __construct(
        protected GroupService $groups,
        protected ClassReportService $reports,
    ) {}

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
                'path_id' => $group->path_id,
            ])->values(),
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

        if (! empty($data['path_id'])) {
            $this->authorizeOwnPath($request, (int) $data['path_id']);
        }

        $group = $this->groups->create($request->user(), $data);

        return ['group' => ['id' => $group->id, 'code' => $group->code, 'title' => $group->title]];
    }

    public function show(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $stageId = $request->integer('stage') ?: null;

        return [
            'group' => $this->presentGroup($group),
            'pending' => $group->pending()->with('user')->get()->map(fn (GroupMember $m) => [
                'id' => $m->id,
                'name' => $m->user->full_name,
                'initial' => $m->user->initial,
                'telegram_id' => $m->user->telegram_id,
            ])->values(),
            'leaderboard' => $this->groups->leaderboard($group, $stageId),
        ];
    }

    public function update(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:60'],
            'subtitle' => ['nullable', 'string', 'max:80'],
            'badge' => ['nullable', 'string', 'max:6'],
        ]);

        $group->update($data);

        return ['group' => $this->presentGroup($group->fresh())];
    }

    public function destroy(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        // Members are removed one by one so each student's copied stages go
        // with them; a bare delete would leave orphaned categories behind.
        foreach ($group->memberships()->get() as $membership) {
            $this->groups->remove($membership);
        }

        $group->delete();

        return ['deleted' => true];
    }

    /** Attaching or swapping the path re-materialises it for every student. */
    public function attachPath(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $data = $request->validate([
            'path_id' => ['required', 'integer', 'exists:paths,id'],
        ]);

        $this->authorizeOwnPath($request, (int) $data['path_id']);

        $group->update($data);
        $group->refresh();

        foreach ($group->students()->get() as $student) {
            $this->groups->materialise($group, $student);
        }

        return ['group' => $this->presentGroup($group->fresh())];
    }

    /** UT-04b — the group's path with a class average on every stage. */
    public function road(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        return $this->reports->road($group) + [
            'group' => [
                'id' => $group->id,
                'title' => $group->title,
                'badge' => $group->badge,
                'members' => $group->members_count,
                'path' => $group->path?->title,
            ],
        ];
    }

    /** UT-05 — one stage, every student, and what they are weakest at. */
    public function stageResults(Request $request, Group $group, PathStage $stage): array
    {
        $this->authorizeOwner($request, $group);

        abort_unless(
            $group->path_id && $stage->path_id === $group->path_id,
            Response::HTTP_NOT_FOUND,
            'Bu bosqich guruh yoʼlida emas.',
        );

        return $this->reports->stageResults($group, $stage) + [
            'group' => ['id' => $group->id, 'title' => $group->title, 'members' => $group->members_count],
            'siblings' => $group->path->stages->map(fn (PathStage $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'title' => $s->title,
            ])->values(),
        ];
    }

    /** UT-MD1 — find a student by the ID they read off their own profile. */
    public function searchStudents(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return ['students' => []];
        }

        $taken = $group->memberships()->whereIn('status', ['pending', 'active'])->pluck('user_id');

        $rows = User::query()
            ->where('id', '!=', $request->user()->id)
            ->where(function ($builder) use ($query) {
                $builder->where('telegram_id', 'like', "%{$query}%")
                    ->orWhere('username', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%");
            })
            ->limit(8)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->full_name,
                'initial' => $user->initial,
                'telegram_id' => $user->telegram_id,
                'level' => $user->cefr_level,
                'already_in' => $taken->contains($user->id),
            ]);

        return ['students' => $rows->values()];
    }

    /** The teacher adds a student they searched for; no approval round-trip. */
    public function addStudent(Request $request, Group $group, NotificationService $notifications): array
    {
        $this->authorizeOwner($request, $group);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        abort_if($data['user_id'] === $request->user()->id, Response::HTTP_CONFLICT,
            'Oʼzingizni qoʼsha olmaysiz.');

        $membership = GroupMember::firstOrNew([
            'group_id' => $group->id,
            'user_id' => $data['user_id'],
        ]);

        abort_if($membership->exists && $membership->status === 'active', Response::HTTP_CONFLICT,
            'Bu oʼquvchi allaqachon guruhda.');

        $membership->status = 'pending';
        $membership->save();

        $this->groups->approve($membership->fresh());

        return ['status' => 'active'];
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

    /** The teacher's home screen — UT-DB. */
    public function dashboard(Request $request, PlanService $plans): array
    {
        $this->authorizeTeacher($request);

        $teacher = $request->user();
        $groups = $teacher->groups()->with('path')->get();

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

        $scored = $groups->map(fn (Group $group) => [
            'id' => $group->id,
            'badge' => $group->badge,
            'title' => $group->title,
            'members' => $group->members_count,
            'path' => $group->path?->title,
            'score' => (int) round(collect($this->groups->leaderboard($group))->avg('score') ?? 0),
        ]);

        $paths = $teacher->paths()->with('stages')->get();

        return [
            'name' => $teacher->first_name ?: $teacher->full_name,
            'students' => $studentIds->count(),
            'groups' => $groups->count(),
            'paths' => $paths->count(),
            'stages' => (int) $paths->sum(fn ($path) => $path->stages->count()),
            'active_today' => $activeToday,
            'week' => $week->all(),
            'week_total' => $week->sum(),
            'today_index' => (int) today()->dayOfWeekIso - 1,
            'pending' => GroupMember::whereIn('group_id', $groups->pluck('id'))
                ->where('status', 'pending')
                ->count(),
            'top_groups' => $scored->sortByDesc('score')->take(3)->values()->all(),
            'top_students' => $this->topStudents($groups),
            'plan' => $plans->summary($teacher),
        ];
    }

    /** UT-09 / UT-WEB — the teacher's own numbers and their public ID. */
    public function profile(Request $request, PlanService $plans): array
    {
        $this->authorizeTeacher($request);

        $teacher = $request->user();
        $groupIds = $teacher->groups()->pluck('id');

        return [
            'teacher_ref' => $teacher->teacherRef(),
            'paths' => $teacher->paths()->count(),
            'groups' => $groupIds->count(),
            'students' => GroupMember::whereIn('group_id', $groupIds)
                ->where('status', 'active')
                ->distinct()
                ->count('user_id'),
            'plan' => $plans->summary($teacher),
        ];
    }

    /** The three best students across every group — UT-WEB's right column. */
    protected function topStudents($groups): array
    {
        return $groups
            ->flatMap(fn (Group $group) => collect($this->groups->leaderboard($group))
                ->take(3)
                ->map(fn (array $row) => $row + ['group' => $group->title, 'badge' => $group->badge]))
            ->sortByDesc('score')
            ->take(3)
            ->values()
            ->map(fn (array $row, int $index) => $row + ['rank' => $index + 1])
            ->all();
    }

    protected function presentGroup(Group $group): array
    {
        $group->loadMissing('path.stages');

        return [
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
                'stages' => $group->path->stages->map(fn (PathStage $stage) => [
                    'id' => $stage->id,
                    'position' => $stage->position,
                    'title' => $stage->title,
                    'type' => $stage->type,
                    'words_count' => $stage->words_count,
                ])->values(),
            ] : null,
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

    protected function authorizeOwnPath(Request $request, int $pathId): void
    {
        abort_unless(
            $request->user()->paths()->whereKey($pathId)->exists(),
            Response::HTTP_FORBIDDEN,
            'Bu yoʼl sizniki emas.',
        );
    }
}
