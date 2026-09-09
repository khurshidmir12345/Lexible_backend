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
    public function __construct(protected \App\Services\Game\NotificationService $notifications) {}

    /** Codes are read out in class, so they are built from clear words. */

    public function create(User $teacher, array $data): Group
    {
        $badge = $data['badge'] ?? Str::upper(Str::substr($data['title'], 0, 2));

        return Group::create([
            'teacher_id' => $teacher->id,
            'path_id' => $data['path_id'] ?? null,
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'badge' => $badge,
            'code' => $this->freshCode(),
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

            $this->notifications->joinRequest($group->teacher_id, $student->full_name, $group->title);
        }

        return $membership->fresh();
    }

    public function approve(GroupMember $membership): GroupMember
    {
        $membership->update(['status' => 'active', 'joined_at' => now()]);
        $membership->group->refreshMembersCount();

        $this->materialise($membership->group, $membership->user);

        $membership->group->loadMissing('teacher');
        $this->notifications->joinedGroup(
            $membership->user_id,
            $membership->group->title,
            $membership->group->teacher?->full_name ?? 'Ustoz',
        );

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
        $index = -1;

        foreach ($path->stages()->with('words')->orderBy('position')->get() as $stage) {
            // The teacher's map is pre-drawn with empty stages; students only
            // receive a stage once its lesson is actually written.
            if ($stage->words->isEmpty()) {
                continue;
            }

            $index++;

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
        $students = $group->students()->get();

        // One query for the whole class instead of one per student, and it
        // also carries the membership id the teacher needs to remove someone.
        $byStudent = Category::whereIn('user_id', $students->pluck('id'))
            ->where('group_id', $group->id)
            ->when($stageId, fn ($q) => $q->where('path_stage_id', $stageId))
            ->get()
            ->groupBy('user_id');

        $memberIds = GroupMember::where('group_id', $group->id)
            ->whereIn('user_id', $students->pluck('id'))
            ->pluck('id', 'user_id');

        return $students
            ->map(function (User $student) use ($byStudent, $memberIds) {
                $categories = $byStudent->get($student->id) ?? collect();

                return [
                    'id' => $student->id,
                    'member_id' => $memberIds[$student->id] ?? null,
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

    /**
     * "LX-7K3M9Q" — minted here, never derived from anything the teacher
     * typed, so two classes called 5-A in two schools can never collide and
     * a code cannot be guessed from a badge. The alphabet drops 0/O, 1/I/L
     * so it survives being read out loud or copied off a whiteboard.
     */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected function freshCode(): string
    {
        do {
            $code = 'LX-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Group::where('code', $code)->exists());

        return $code;
    }
}
