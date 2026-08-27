<?php

namespace App\Services\Teaching;

use App\Models\GroupMember;
use App\Models\User;

/**
 * UT-08 / UT-08b — who pays for a class.
 *
 * `teacher` mode: the teacher buys a seat bundle and every student in their
 * groups plays for free. `student` mode: the teacher pays nothing and each
 * student pays a monthly fee for the path to open.
 *
 * Payment capture itself is not wired up yet — choosing a plan records the
 * request and the UI says it is awaiting payment.
 */
class PlanService
{
    /** Every seat bundle, with the current one marked. */
    public function plans(User $teacher): array
    {
        $current = $this->currentSeats($teacher);

        return collect(config('game.teaching.plans'))
            ->map(fn (array $plan) => $plan + [
                'is_current' => $plan['seats'] === $current,
                'is_requested' => $teacher->plan_requested_seats === $plan['seats'],
            ])
            ->all();
    }

    /** Free tier until a paid bundle has been granted and is still running. */
    public function currentSeats(User $teacher): int
    {
        $free = (int) (config('game.teaching.plans.0.seats') ?? 10);

        if (! $teacher->plan_seats || ! $teacher->plan_until?->isFuture()) {
            return $free;
        }

        return (int) $teacher->plan_seats;
    }

    public function seatsUsed(User $teacher): int
    {
        return GroupMember::whereIn('group_id', $teacher->groups()->pluck('id'))
            ->where('status', 'active')
            ->distinct()
            ->count('user_id');
    }

    /** Everything UT-08, UT-08b and the UT-09 tariff card need in one call. */
    public function summary(User $teacher): array
    {
        $seats = $this->currentSeats($teacher);
        $used = $this->seatsUsed($teacher);
        $price = collect(config('game.teaching.plans'))->firstWhere('seats', $seats)['price'] ?? 0;

        $memberIds = GroupMember::whereIn('group_id', $teacher->groups()->pluck('id'))
            ->where('status', 'active');

        $paid = (clone $memberIds)->where('paid_until', '>', now())->distinct()->count('user_id');

        return [
            'billing_mode' => $teacher->billing_mode ?? 'teacher',
            'seats' => $seats,
            'seats_used' => $used,
            'seats_left' => max($seats - $used, 0),
            'price' => $price,
            'over_limit' => $used > $seats,
            'renews_at' => $teacher->plan_until?->toDateString(),
            'requested_seats' => $teacher->plan_requested_seats,
            'student_price' => (int) config('game.teaching.student_price'),
            'students_total' => $used,
            'students_paid' => $paid,
            'students_unpaid' => max($used - $paid, 0),
            'plans' => $this->plans($teacher),
        ];
    }

    /** Records the choice; a real payment flow would confirm it later. */
    public function request(User $teacher, int $seats): array
    {
        $plan = collect(config('game.teaching.plans'))->firstWhere('seats', $seats);

        abort_unless($plan, 422, 'Bunday tarif yoʼq.');

        // The free tier needs no payment, so it is granted immediately.
        if ((int) $plan['price'] === 0) {
            $teacher->update([
                'plan_seats' => 0,
                'plan_requested_seats' => null,
                'plan_until' => null,
            ]);

            return $this->summary($teacher->fresh()) + ['status' => 'active'];
        }

        $teacher->update(['plan_requested_seats' => $seats]);

        return $this->summary($teacher->fresh()) + ['status' => 'pending'];
    }

    public function setBillingMode(User $teacher, string $mode): array
    {
        abort_unless(in_array($mode, ['teacher', 'student'], true), 422, 'Notoʼgʼri rejim.');

        $teacher->update(['billing_mode' => $mode]);

        return $this->summary($teacher->fresh());
    }
}
