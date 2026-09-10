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
use Illuminate\Support\Str;

/**
 * A class-wide contest over one path stage.
 *
 * The shape is the duel's, widened: one frozen question set, one test session
 * per participant, ranked at the end by correct answers and then by time. The
 * difference is who drives it — the teacher opens the lobby, watches students
 * arrive, and decides when everybody starts.
 *
 * Time is the server's: a player's clock starts when their paper is handed
 * out and stops when their finish lands, so a tie on score is settled by
 * something nobody can edit. A round may also carry a deadline; once it
 * passes, whoever is still answering is finished with what they have.
 */
class CompetitionService
{
    public function __construct(
        protected TestBuilder $builder,
        protected NotificationService $notifications,
    ) {}

    /**
     * `$group` is optional: UT-MD2 lets a teacher run any stage as an open
     * game, where the invite link is the only thing gating entry.
     */
    public function create(User $teacher, ?Group $group, PathStage $stage, ?array $types = null, ?int $minutes = null): Competition
    {
        abort_unless($stage->path->teacher_id === $teacher->id, 403, 'Bu bosqich sizniki emas.');

        abort_if($group && $stage->path_id !== $group->path_id, 422,
            'Bu bosqich guruh yoʼlida emas.');

        $words = $stage->words()->pluck('words.id');

        abort_if($words->isEmpty(), 422, 'Bu bosqichda soʼz yoʼq.');

        $min = (int) config('game.session.min_words');
        abort_if($words->count() < $min, 422, "Bellashuv uchun bosqichda kamida {$min} ta soʼz kerak.");

        $types = $this->raceTypes($types) ?: $this->defaultTypes($stage);

        // A stale lobby for the same stage would confuse the class, so it is
        // retired the moment a new one opens.
        Competition::where('teacher_id', $teacher->id)
            ->where('path_stage_id', $stage->id)
            ->where('status', 'lobby')
            ->update(['status' => 'cancelled']);

        $competition = Competition::create([
            'code' => $this->freshCode(),
            'teacher_id' => $teacher->id,
            'group_id' => $group?->id,
            'path_stage_id' => $stage->id,
            'types' => $types,
            'word_ids' => $words->all(),
            'questions_count' => $words->count(),
            'duration_minutes' => $minutes ?: null,
            'status' => 'lobby',
            'expires_at' => now()->addMinutes(config('game.competition.lobby_ttl_minutes')),
        ])->fresh();

        // A class game calls the whole roster in; an open game has no roster
        // and is spread by its link.
        if ($group) {
            $this->callRoster($competition);
        }

        return $competition;
    }

    /**
     * Every student of the class who has not joined yet hears about the
     * lobby from the bot, with a button straight into it. Returns how many
     * were told.
     */
    public function callRoster(Competition $competition): int
    {
        abort_unless($competition->group, 422, 'Ochiq oʼyinda roʼyxat yoʼq — havolani tarqating.');
        abort_unless($competition->status === 'lobby', 409, 'Lobbi yopilgan.');

        $competition->loadMissing(['group', 'stage', 'teacher']);

        $where = $competition->group->title.' · '.($competition->stage?->title ?: 'Musobaqa');
        $joined = $competition->players()->pluck('user_id')->flip();
        $count = 0;

        foreach ($competition->group->students()->get() as $student) {
            if ($joined->has($student->id)) {
                continue;
            }

            $this->notifications->competitionOpened(
                $student,
                $competition->teacher?->full_name ?? 'Ustoz',
                $where,
                $competition->code,
                $competition->duration_minutes,
            );
            $count++;
        }

        $competition->update(['notified_at' => now()]);

        return $count;
    }

    /** A student arrives through the invite link. */
    public function join(Competition $competition, User $student): CompetitionPlayer
    {
        $this->settle($competition);

        // Someone who was in the round may come back to it — through the
        // same link — after it ended, and is shown the board, not a refusal.
        $existing = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        abort_if($competition->status === 'finished', 409, 'Bu musobaqa tugagan.');
        abort_if($competition->status === 'cancelled', 409, 'Bu musobaqa bekor qilingan.');

        // An open game has no roster to check against — the link is the gate.
        if ($competition->group) {
            $isMember = $competition->group->students()->whereKey($student->id)->exists();
            abort_unless($isMember, 403, 'Siz bu guruh oʼquvchisi emassiz.');
        }

        abort_if($competition->teacher_id === $student->id, 409,
            'Oʼz musobaqangizda oʼynay olmaysiz.');

        return CompetitionPlayer::firstOrCreate(
            ['competition_id' => $competition->id, 'user_id' => $student->id],
            ['status' => 'joined', 'joined_at' => now()],
        );
    }

    /** The teacher releases the class; the clock, if there is one, starts now. */
    public function start(Competition $competition): Competition
    {
        abort_unless($competition->status === 'lobby', 409, 'Musobaqa allaqachon boshlangan.');
        abort_if($competition->players()->count() === 0, 422, 'Hech kim qoʼshilmagan.');

        $competition->update([
            'status' => 'playing',
            'started_at' => now(),
            'deadline_at' => $competition->duration_minutes
                ? now()->addMinutes($competition->duration_minutes)
                : null,
        ]);

        $where = $competition->group?->title ?? ($competition->stage?->title ?: 'Musobaqa');

        foreach ($competition->players()->with('user')->get() as $player) {
            $this->notifications->competitionStarted(
                $player->user_id, $where, $competition->code, $competition->duration_minutes,
            );
        }

        return $competition->fresh();
    }

    /**
     * The caller's session, created on first call. Questions come from the
     * frozen word list, so every student answers exactly the same paper.
     */
    public function session(Competition $competition, User $student): TestSession
    {
        $this->settle($competition);

        abort_unless($competition->status === 'playing', 409, $competition->status === 'finished'
            ? 'Musobaqa tugagan.'
            : 'Musobaqa hali boshlanmagan.');

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

    /**
     * Records one student's finish; ranks the field once everyone is in.
     *
     * The score is read off the player's own session, and the time is
     * measured here — from the paper being handed out to this call — so
     * neither can be sent in by a modified client. The numbers the client
     * reports are only a fallback for a session that never existed.
     */
    public function finish(Competition $competition, User $student, int $score, int $total, int $durationMs): Competition
    {
        $player = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->firstOrFail();

        if ($player->status !== 'finished') {
            $session = $player->test_session_id ? TestSession::find($player->test_session_id) : null;

            if ($session) {
                $score = (int) $session->correct_count;
                $total = (int) ($session->questions_count ?: $player->total);
                $durationMs = $this->elapsedMs($session, $competition);

                // The paper is closed here too, so the record does not depend
                // on the client also calling the plain test finish.
                if ($session->status === 'active') {
                    $session->update(['status' => 'finished', 'duration_ms' => $durationMs, 'finished_at' => now()]);
                }
            }

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

    /**
     * Ends the round and freezes the ranking, whoever is still playing.
     *
     * A player caught mid-paper keeps what they answered; every question they
     * never reached counts as wrong. One who joined but never opened the
     * paper is simply listed as not having played.
     */
    public function close(Competition $competition): Competition
    {
        if ($competition->status === 'finished') {
            return $competition;
        }

        foreach ($competition->players()->where('status', 'playing')->get() as $player) {
            $this->timeOut($player, $competition);
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

    /**
     * Closes a round whose clock has run out. Called before every read so
     * the board, the lobby and the students' polls all see the same end
     * without a scheduler having to notice.
     */
    public function settle(Competition $competition): Competition
    {
        if ($competition->timeIsUp()) {
            return $this->close($competition);
        }

        return $competition;
    }

    /** Finish a player with what they have; the rest of the paper is wrong. */
    protected function timeOut(CompetitionPlayer $player, Competition $competition): void
    {
        $session = $player->test_session_id ? TestSession::find($player->test_session_id) : null;

        if ($session && $session->status === 'active') {
            $session->update([
                'status' => 'finished',
                'duration_ms' => $this->elapsedMs($session, $competition),
                'finished_at' => now(),
            ]);
        }

        $player->update([
            'status' => 'finished',
            'timed_out' => true,
            'score' => (int) ($session?->correct_count ?? 0),
            'total' => (int) ($session?->questions_count ?: $player->total),
            // Running out the clock is the slowest possible finish.
            'duration_ms' => $session ? $this->elapsedMs($session, $competition) : 0,
            'finished_at' => now(),
        ]);
    }

    /**
     * Milliseconds from the paper being handed out to now, never past the
     * deadline — a finish that lands a beat after the clock counts as the
     * whole clock, not more.
     */
    protected function elapsedMs(TestSession $session, Competition $competition): int
    {
        $start = $session->started_at ?? $session->created_at ?? now();
        $end = now();

        if ($competition->deadline_at && $end->greaterThan($competition->deadline_at)) {
            $end = $competition->deadline_at;
        }

        return max(1, (int) $start->diffInMilliseconds($end, true));
    }

    /**
     * More correct answers wins; a tie goes to whoever finished faster.
     * Players who never opened the paper come last, in joining order.
     */
    protected function rank(Competition $competition): void
    {
        $ordered = $competition->players()
            ->orderByRaw("CASE WHEN status = 'finished' THEN 0 ELSE 1 END")
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_ms = 0 THEN 1 ELSE 0 END')
            ->orderBy('duration_ms')
            ->orderBy('id')
            ->get();

        foreach ($ordered->values() as $index => $player) {
            $player->update(['rank' => $index + 1]);
        }
    }

    /**
     * Everyone in the class, whether or not they have opened the link — the
     * lobby has to show who is still missing, not only who is present.
     *
     * Once the round is running the same list is the live board: correct so
     * far, how far through the paper, and the time on each player's clock,
     * best first.
     */
    public function lobby(Competition $competition): array
    {
        $this->settle($competition);
        $competition->loadMissing(['group', 'stage', 'players.user', 'players.session']);

        $joined = $competition->players->keyBy('user_id');

        // A group game lists the whole class so the missing names show up too.
        // An open game can only list whoever has actually arrived.
        $roster = $competition->group
            ? $competition->group->students()->orderBy('first_name')->get()
            : $competition->players->map(fn (CompetitionPlayer $player) => $player->user)->filter()->values();

        $rows = $roster
            ->map(function (User $student) use ($joined, $competition) {
                $player = $joined->get($student->id);
                $live = $this->liveRow($player, $competition);

                return [
                    'id' => $student->id,
                    'name' => trim("{$student->first_name} {$student->last_name}") ?: 'Oʼquvchi',
                    'avatar' => $student->photo_url,
                    'joined' => (bool) $player,
                    'status' => match ($player?->status) {
                        'finished' => 'finished',
                        'playing' => 'playing',
                        'joined' => 'ready',
                        default => 'absent',
                    },
                ] + $live;
            })
            ->values();

        if ($competition->status !== 'lobby') {
            $rows = $rows
                ->sortBy([['score', 'desc'], ['answered', 'desc'], ['elapsed_ms', 'asc']])
                ->values();
        }

        return [
            'id' => $competition->id,
            'code' => $competition->code,
            'status' => $competition->status,
            'open' => $competition->group_id === null,
            'group' => $competition->group?->title ?? 'Ochiq oʼyin',
            'group_id' => $competition->group_id,
            'stage_id' => $competition->path_stage_id,
            'stage' => $competition->stage?->position,
            'stage_title' => $competition->stage?->title,
            'types' => $competition->types,
            'words' => count($competition->word_ids),
            'questions' => $competition->questions_count,
            'invite_link' => $competition->inviteLink(),
            'joined_count' => $competition->players->count(),
            'finished_count' => $competition->players->where('status', 'finished')->count(),
            'roster_count' => $competition->group ? $roster->count() : null,
            'notified_at' => $competition->notified_at?->toIso8601String(),
            'duration_minutes' => $competition->duration_minutes,
            'started_at' => $competition->started_at?->toIso8601String(),
            'deadline_at' => $competition->deadline_at?->toIso8601String(),
            'remaining_seconds' => $competition->remainingSeconds(),
            'students' => $rows,
        ];
    }

    /**
     * What a player has done so far, read straight off their session while
     * they are still answering and off the frozen row once they are done.
     */
    protected function liveRow(?CompetitionPlayer $player, Competition $competition): array
    {
        if (! $player) {
            return ['score' => 0, 'answered' => 0, 'total' => $competition->questions_count,
                'elapsed_ms' => 0, 'duration' => null, 'timed_out' => false];
        }

        $session = $player->session;

        if ($player->status === 'finished') {
            return [
                'score' => (int) $player->score,
                'answered' => (int) ($session?->answered_count ?? $player->total),
                'total' => (int) $player->total,
                'elapsed_ms' => (int) $player->duration_ms,
                'duration' => $this->clock((int) $player->duration_ms),
                'timed_out' => (bool) $player->timed_out,
            ];
        }

        $elapsed = $session ? $this->elapsedMs($session, $competition) : 0;

        return [
            'score' => (int) ($session?->correct_count ?? 0),
            'answered' => (int) ($session?->answered_count ?? 0),
            'total' => (int) ($session?->questions_count ?: $player->total ?: $competition->questions_count),
            'elapsed_ms' => $elapsed,
            'duration' => $session ? $this->clock($elapsed) : null,
            'timed_out' => false,
        ];
    }

    /** The final board: podium first, then the rest in order. */
    public function results(Competition $competition): array
    {
        $this->settle($competition);
        $competition->loadMissing(['group', 'stage', 'players.user', 'players.session']);

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
                'answered' => (int) ($player->session?->answered_count ?? 0),
                'accuracy' => $player->accuracy(),
                'duration' => $this->clock($player->duration_ms),
                'duration_ms' => (int) $player->duration_ms,
                'finished' => $player->status === 'finished',
                'played' => $player->status !== 'joined',
                'timed_out' => (bool) $player->timed_out,
            ]);

        return [
            'id' => $competition->id,
            'code' => $competition->code,
            'status' => $competition->status,
            'open' => $competition->group_id === null,
            'group' => $competition->group?->title ?? 'Ochiq oʼyin',
            'group_id' => $competition->group_id,
            'stage_id' => $competition->path_stage_id,
            'stage' => $competition->stage?->position,
            'stage_title' => $competition->stage?->title,
            'types' => $competition->types,
            'questions' => $competition->questions_count,
            'duration_minutes' => $competition->duration_minutes,
            'started_at' => $competition->started_at?->toIso8601String(),
            'finished_at' => $competition->finished_at?->toIso8601String(),
            'participants' => $rows->count(),
            'podium' => $rows->take(3)->values(),
            'standings' => $rows,
        ];
    }

    /** What a student polls between joining and starting. */
    public function studentState(Competition $competition, User $student): array
    {
        $this->settle($competition);

        $player = CompetitionPlayer::where('competition_id', $competition->id)
            ->where('user_id', $student->id)
            ->first();

        return [
            'code' => $competition->code,
            'status' => $competition->status,
            'open' => $competition->group_id === null,
            'group' => $competition->group?->title ?? 'Ochiq oʼyin',
            'stage_id' => $competition->path_stage_id,
            'stage' => $competition->stage?->position,
            'stage_title' => $competition->stage?->title,
            'types' => $competition->types,
            'words' => count($competition->word_ids),
            'questions' => $competition->questions_count,
            'duration_minutes' => $competition->duration_minutes,
            'deadline_at' => $competition->deadline_at?->toIso8601String(),
            'remaining_seconds' => $competition->remainingSeconds(),
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

    /**
     * A class game is played with the exercises the teacher switched on for
     * the path — all but the flashcard, which has no right answer to race
     * on. A path with nothing chosen falls back to the usual pair.
     *
     * @return list<string>
     */
    protected function defaultTypes(PathStage $stage): array
    {
        if (! $stage->path->types) {
            return config('game.competition.types');
        }

        $chosen = array_values(array_diff($stage->path->allowedTypes(), ['card']));

        return $chosen ?: config('game.competition.types');
    }

    /** The teacher's pick, minus the flashcard — it cannot be scored. */
    protected function raceTypes(?array $types): array
    {
        return array_values(array_intersect(
            array_diff(config('game.test_types'), ['card']),
            $types ?? [],
        ));
    }

    public function clock(int $ms): string
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
