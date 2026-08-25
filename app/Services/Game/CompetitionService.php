<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\Competition;
use App\Models\CompetitionPlayer;
use App\Models\Group;
use App\Models\PathStage;
use App\Models\TestSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A class-wide contest over one path stage.
 *
 * The shape is the duel's, widened: one frozen question set, one test session
 * per participant, ranked at the end by correct answers and then by time. The
 * difference is who drives it — the teacher opens the lobby, watches students
 * arrive, and decides when everybody starts.
 */
class CompetitionService
{
    public function __construct(
        protected TestBuilder $builder,
        protected NotificationService $notifications,
    ) {}

    public function create(User $teacher, Group $group, PathStage $stage, ?array $types = null): Competition
    {
        abort_unless($stage->path_id === $group->path_id, 422,
            'Bu bosqich guruh yoʼlida emas.');

        $words = $stage->words()->pluck('words.id');

        abort_if($words->isEmpty(), 422, 'Bu bosqichda soʼz yoʼq.');

        $types = $types ?: config('game.competition.types');

        // A stale lobby for the same stage would confuse the class, so it is
        // retired the moment a new one opens.
        Competition::where('group_id', $group->id)
            ->where('path_stage_id', $stage->id)
            ->where('status', 'lobby')
            ->update(['status' => 'cancelled']);

        return Competition::create([
            'code' => $this->freshCode(),
            'teacher_id' => $teacher->id,
            'group_id' => $group->id,
            'path_stage_id' => $stage->id,
            'types' => $types,
            'word_ids' => $words->all(),
            'questions_count' => $words->count(),
            'status' => 'lobby',
            'expires_at' => now()->addMinutes(config('game.competition.lobby_ttl_minutes')),
        ])->fresh();
    }

    /** A student arrives through the invite link. */
    public function join(Competition $competition, User $student): CompetitionPlayer
    {
        abort_if($competition->status === 'finished', 409, 'Bu musobaqa tugagan.');
        abort_if($competition->status === 'cancelled', 409, 'Bu musobaqa bekor qilingan.');

        $isMember = $competition->group->students()->whereKey($student->id)->exists();
        abort_unless($isMember, 403, 'Siz bu guruh oʼquvchisi emassiz.');

        return CompetitionPlayer::firstOrCreate(
            ['competition_id' => $competition->id, 'user_id' => $student->id],
            ['status' => 'joined', 'joined_at' => now()],
        );
    }

    /** The teacher releases the class. */
    public function start(Competition $competition): Competition
    {
        abort_unless($competition->status === 'lobby', 409, 'Musobaqa allaqachon boshlangan.');
        abort_if($competition->players()->count() === 0, 422, 'Hech kim qoʼshilmagan.');

        $competition->update(['status' => 'playing', 'started_at' => now()]);

        foreach ($competition->players()->with('user')->get() as $player) {
            $this->notifications->competitionStarted($player->user_id, $competition->group->title);
        }

        return $competition->fresh();
    }

    /**
     * The caller's session, created on first call. Questions come from the
     * frozen word list, so every student answers exactly the same paper.
     */
    public function session(Competition $competition, User $student): TestSession
    {
        abort_unless($competition->status === 'playing', 409, 'Musobaqa hali boshlanmagan.');

        $player = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->first();

        abort_unless($player, 403, 'Siz bu musobaqada emassiz.');

        if ($player->test_session_id && $session = TestSession::find($player->test_session_id)) {
            return $session;
        }

        $words = Word::whereIn('id', $competition->word_ids)->get();
        $questions = $this->builder->build(
            $this->categoryFor($competition, $student),
            $competition->types,
            $words,
            $student->native_lang,
        );

        abort_if($questions === [], 422, 'Savol tuzib boʼlmadi.');

        $session = TestSession::create([
            'user_id' => $student->id,
            'category_id' => $this->categoryFor($competition, $student)?->id,
            'competition_id' => $competition->id,
            'types' => $competition->types,
            'scope' => 'all',
            'status' => 'active',
            'questions_count' => count($questions),
            'payload' => $questions,
            'started_at' => now(),
        ]);

        $player->update([
            'test_session_id' => $session->id,
            'status' => 'playing',
            'total' => count($questions),
        ]);

        return $session;
    }

    /** Records one student's finish; ranks the field once everyone is in. */
    public function finish(Competition $competition, User $student, int $score, int $total, int $durationMs): Competition
    {
        $player = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        if ($player->status !== 'finished') {
            $player->update([
                'status' => 'finished',
                'score' => $score,
                'total' => $total ?: $player->total,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);
        }

        $pending = $competition->players()->where('status', '!=', 'finished')->count();

        if ($pending === 0) {
            $this->close($competition);
        }

        return $competition->fresh();
    }

    /** Ends the round and freezes the ranking, whoever is still playing. */
    public function close(Competition $competition): Competition
    {
        if ($competition->status === 'finished') {
            return $competition;
        }

        $this->rank($competition);

        $competition->update(['status' => 'finished', 'finished_at' => now()]);

        foreach ($competition->players()->get() as $player) {
            $this->notifications->competitionFinished(
                $player->user_id,
                $player->rank ?? 0,
                $competition->players()->count(),
            );
        }

        return $competition->fresh();
    }

    /** More correct answers wins; a tie goes to whoever finished faster. */
    protected function rank(Competition $competition): void
    {
        $ordered = $competition->players()
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_ms = 0 THEN 1 ELSE 0 END')
            ->orderBy('duration_ms')
            ->get();

        foreach ($ordered->values() as $index => $player) {
            $player->update(['rank' => $index + 1]);
        }
    }

    /**
     * Everyone in the class, whether or not they have opened the link — the
     * lobby has to show who is still missing, not only who is present.
     */
    public function lobby(Competition $competition): array
    {
        $competition->loadMissing(['group', 'stage', 'players.user']);

        $joined = $competition->players->keyBy('user_id');

        $rows = $competition->group->students()->orderBy('first_name')->get()
            ->map(fn (User $student) => [
                'id' => $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}") ?: 'Oʼquvchi',
                'avatar' => $student->photo_url,
                'joined' => $joined->has($student->id),
                'status' => match ($joined->get($student->id)?->status) {
                    'finished' => 'finished',
                    'playing' => 'playing',
                    'joined' => 'ready',
                    default => 'absent',
                },
            ])
            ->values();

        return [
            'id' => $competition->id,
            'code' => $competition->code,
            'status' => $competition->status,
            'group' => $competition->group->title,
            'stage' => $competition->stage?->position,
            'stage_title' => $competition->stage?->title,
            'words' => count($competition->word_ids),
            'questions' => $competition->questions_count,
            'invite_link' => $competition->inviteLink(),
            'joined_count' => $competition->players->count(),
            'students' => $rows,
        ];
    }

    /** The final board: podium first, then the rest in order. */
    public function results(Competition $competition): array
    {
        $competition->loadMissing(['group', 'stage', 'players.user']);

        // While the round is still live nobody is ranked yet, so the board
        // shows the running order instead of a column of blanks.
        $rows = $competition->players
            ->sortBy([['rank', 'asc'], ['score', 'desc'], ['duration_ms', 'asc']])
            ->values()
            ->map(fn (CompetitionPlayer $player, int $index) => [
                'rank' => $player->rank ?? $index + 1,
                'id' => $player->user_id,
                'name' => trim("{$player->user?->first_name} {$player->user?->last_name}") ?: 'Oʼquvchi',
                'avatar' => $player->user?->photo_url,
                'score' => $player->score,
                'total' => $player->total,
                'accuracy' => $player->accuracy(),
                'duration' => $this->clock($player->duration_ms),
                'finished' => $player->status === 'finished',
            ]);

        return [
            'id' => $competition->id,
            'code' => $competition->code,
            'status' => $competition->status,
            'group' => $competition->group->title,
            'stage' => $competition->stage?->position,
            'questions' => $competition->questions_count,
            'participants' => $rows->count(),
            'podium' => $rows->take(3)->values(),
            'standings' => $rows,
        ];
    }

    /** What a student polls between joining and starting. */
    public function studentState(Competition $competition, User $student): array
    {
        $player = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->first();

        return [
            'code' => $competition->code,
            'status' => $competition->status,
            'group' => $competition->group->title,
            'stage' => $competition->stage?->position,
            'questions' => $competition->questions_count,
            'joined' => (bool) $player,
            'my_status' => $player?->status,
            'my_rank' => $player?->rank,
            'joined_count' => $competition->players()->count(),
        ];
    }

    /**
     * A competition scores into the student's own copy of the stage when they
     * have one, so mastery still moves; otherwise it is a standalone round.
     */
    protected function categoryFor(Competition $competition, User $student): ?Category
    {
        return Category::where('user_id', $student->id)
            ->where('path_stage_id', $competition->path_stage_id)
            ->first();
    }

    protected function clock(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    protected function freshCode(): string
    {
        do {
            $code = 'VS'.Str::upper(Str::random(4));
        } while (Competition::where('code', $code)->exists());

        return $code;
    }
}
