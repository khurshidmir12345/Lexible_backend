<?php

namespace App\Services\Teaching;

use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Groups, join codes, and turning a teacher's path into stages a student can
 * actually play.
 *
 * A group stage is materialised as an ordinary `category` for each student, so
 * tests, mastery, duels and the road map keep working without knowing a
 * teacher was involved.
 */
class GroupService
{
    /** Codes are read out in class, so they are built from clear words. */
    protected const WORDS = [
        'KITOB', 'QUYOSH', 'DARYO', 'BAHOR', 'YULDUZ', 'CHINOR',
        'BULUT', 'DENGIZ', 'GULZOR', 'SHAMOL', 'OLTIN', 'LOLA',
    ];

    public function create(User $teacher, array $data): Group
    {
        $badge = $data['badge'] ?? Str::upper(Str::substr($data['title'], 0, 2));

        return Group::create([
            'teacher_id' => $teacher->id,
            'path_id' => $data['path_id'] ?? null,
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'badge' => $badge,
            'code' => $this->freshCode($badge),
        ])->fresh();
    }

    /**
     * A student asks to join. The teacher approves, and only then do the
     * stages appear on the student's map.
     */
    public function requestJoin(Group $group, User $student): GroupMember
    {
        abort_if($group->teacher_id === $student->id, 409, 'Oʼz guruhingizga qoʼshila olmaysiz.');

        $membership = GroupMember::firstOrNew([
            'group_id' => $group->id,
            'user_id' => $student->id,
        ]);

        // Someone who was removed may ask again.
        if (! $membership->exists || $membership->status === 'removed') {
            $membership->status = 'pending';
            $membership->save();
        }

        return $membership->fresh();
    }

    public function approve(GroupMember $membership): GroupMember
    {
        $membership->update(['status' => 'active', 'joined_at' => now()]);
        $membership->group->refreshMembersCount();

        $this->materialise($membership->group, $membership->user);

        return $membership->fresh();
    }

    public function remove(GroupMember $membership): void
    {
        $membership->update(['status' => 'removed']);
        $membership->group->refreshMembersCount();

        // The stages go away; the words already learned stay learned.
        Category::where('user_id', $membership->user_id)
            ->where('group_id', $membership->group_id)
            ->delete();
    }

    /**
     * Copies the group's path into the student's own stages. Stages already
     * copied are left alone, so progress survives the teacher editing a path.
     */
    public function materialise(Group $group, User $student): int
    {
        $path = $group->path;

        if (! $path) {
            return 0;
        }

        $offset = (int) Category::where('user_id', $student->id)->max('position');
        $created = 0;

        foreach ($path->stages()->with('words')->get() as $index => $stage) {
            $exists = Category::where('user_id', $student->id)
                ->where('path_stage_id', $stage->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $category = Category::create([
                'user_id' => $student->id,
                'group_id' => $group->id,
                'path_stage_id' => $stage->id,
                'position' => $offset + $index + 1,
                'title' => $stage->title,
                'type' => $stage->type,
                // The first handed-down stage is open; the rest unlock in turn.
                'status' => $index === 0 ? 'in_progress' : 'locked',
                'unlock_date' => now()->addDays($index * 7)->startOfDay(),
            ]);

            if ($stage->words->isNotEmpty()) {
                $category->words()->attach(
                    $stage->words->values()->mapWithKeys(fn ($word, $i) => [
                        $word->id => ['sort_order' => $i, 'created_at' => now()],
                    ])->all(),
                );
            }

            $category->refreshWordsCount();
            $created++;
        }

        return $created;
    }

    /** Ranking inside a group, by how well the shared stages are mastered. */
    public function leaderboard(Group $group, ?int $stageId = null): array
    {
        return $group->students()->get()
            ->map(function (User $student) use ($group, $stageId) {
                $categories = Category::where('user_id', $student->id)
                    ->where('group_id', $group->id)
                    ->when($stageId, fn ($q) => $q->where('path_stage_id', $stageId))
                    ->get();

                return [
                    'id' => $student->id,
                    'name' => $student->full_name,
                    'initial' => $student->initial,
                    'photo' => $student->photo_url,
                    'score' => $categories->isEmpty() ? 0 : (int) round($categories->avg('progress')),
                    'streak' => $student->streak_days,
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->map(fn ($row, $index) => $row + ['rank' => $index + 1])
            ->all();
    }

    /** "5A-KITOB" — the badge plus a word, so it reads well on a whiteboard. */
    protected function freshCode(string $badge): string
    {
        $badge = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $badge)) ?: 'GR';

        do {
            $code = $badge.'-'.self::WORDS[array_rand(self::WORDS)];
        } while (Group::where('code', $code)->exists());

        return $code;
    }
}
