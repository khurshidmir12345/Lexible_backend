<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\Game\NotificationService;
use App\Services\Teaching\PlanService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** UT-08 / UT-08b — seat bundles, or a per-student subscription. */
class PlanController extends Controller
{
    public function __construct(protected PlanService $plans) {}

    public function show(Request $request): array
    {
        $this->authorizeTeacher($request);

        return ['plan' => $this->plans->summary($request->user())];
    }

    public function choose(Request $request): array
    {
        $this->authorizeTeacher($request);

        $seats = collect(config('game.teaching.plans'))->pluck('seats')->all();

        $data = $request->validate([
            'seats' => ['required', 'integer', Rule::in($seats)],
        ]);

        return ['plan' => $this->plans->request($request->user(), (int) $data['seats'])];
    }

    public function billingMode(Request $request): array
    {
        $this->authorizeTeacher($request);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['teacher', 'student'])],
        ]);

        return ['plan' => $this->plans->setBillingMode($request->user(), $data['mode'])];
    }

    /** UT-08b — nudges the students who have not paid this month. */
    public function remind(Request $request, NotificationService $notifications): array
    {
        $this->authorizeTeacher($request);

        $teacher = $request->user();
        $groupIds = $teacher->groups()->pluck('id');

        $unpaid = GroupMember::with('group')
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('paid_until')->orWhere('paid_until', '<=', now()))
            ->get();

        foreach ($unpaid as $membership) {
            $notifications->push(
                $membership->user_id,
                'payment',
                'Toʼlov eslatmasi',
                "«{$membership->group->title}» yoʼli ochiq qolishi uchun oylik toʼlovni amalga oshiring.",
                '💳',
            );
        }

        return ['reminded' => $unpaid->count()];
    }

    /** The seat counter is per teacher, so a group over the cap is blocked. */
    public function canAdmit(Request $request, Group $group): array
    {
        $this->authorizeTeacher($request);
        abort_unless($group->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);

        $summary = $this->plans->summary($request->user());

        return ['can_admit' => ! $summary['over_limit'], 'plan' => $summary];
    }

    protected function authorizeTeacher(Request $request): void
    {
        abort_unless($request->user()->isTeacher(), Response::HTTP_FORBIDDEN, 'Bu boʼlim ustozlar uchun.');
    }
}
