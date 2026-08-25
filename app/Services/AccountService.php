<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing an account.
 *
 * Every table that belongs to a player cascades from `users`, so the delete
 * itself is one statement. What needs care is the bookkeeping that survives
 * them — group rosters counted on the group row, and the fact that deleting a
 * teacher takes their whole curriculum with it.
 */
class AccountService
{
    /** What the confirmation screen has to warn about before the tap. */
    public function impact(User $user): array
    {
        $groups = Group::where('teacher_id', $user->id)->get();

        return [
            'role' => $user->role,
            'words_learned' => $user->words_learned,
            'coins' => $user->coins,
            'streak_days' => $user->streak_days,
            'groups_taught' => $groups->count(),
            'students_affected' => (int) $groups->sum('members_count'),
        ];
    }

    public function delete(User $user): void
    {
        // Rosters the player is leaving; their count is stored on the group.
        $affected = DB::table('group_members')
            ->where('user_id', $user->id)
            ->pluck('group_id');

        DB::transaction(function () use ($user, $affected) {
            $user->delete();

            Group::whereIn('id', $affected)->each(fn (Group $group) => $group->refreshMembersCount());
        });
    }
}
