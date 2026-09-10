<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Group;
use App\Models\PathStage;
use App\Services\Game\CompetitionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** The teacher side of a contest: open the lobby, start it, read the board. */
class CompetitionController extends Controller
{
    public function __construct(protected CompetitionService $competitions) {}

    /** UT-05 — a contest over a stage of the group's own path. */
    public function store(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $data = $request->validate([
            'path_stage_id' => ['required', 'integer', 'exists:path_stages,id'],
            'types' => ['nullable', 'array', 'min:1'],
            'types.*' => [Rule::in(config('game.test_types'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
        ]);

        $stage = PathStage::findOrFail($data['path_stage_id']);

        $competition = $this->competitions->create(
            $request->user(), $group, $stage, $data['types'] ?? null, $data['duration_minutes'] ?? null,
        );

        return ['competition' => $this->competitions->lobby($competition)];
    }

    /**
     * UT-MD2 — the same thing without a class: the teacher hands out the link
     * and whoever opens it plays.
     */
    public function open(Request $request, PathStage $stage): array
    {
        $this->authorizeTeacher($request);

        $data = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'types' => ['nullable', 'array', 'min:1'],
            'types.*' => [Rule::in(config('game.test_types'))],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
        ]);

        $group = null;

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            $this->authorizeOwner($request, $group);
        }

        $competition = $this->competitions->create(
            $request->user(), $group, $stage, $data['types'] ?? null, $data['duration_minutes'] ?? null,
        );

        return ['competition' => $this->competitions->lobby($competition)];
    }

    /** Calls the class in again — whoever has not joined gets the bot message once more. */
    public function notify(Request $request, Competition $competition): array
    {
        $this->authorizeCompetition($request, $competition);

        $sent = $this->competitions->callRoster($competition);

        return ['sent' => $sent, 'competition' => $this->competitions->lobby($competition->fresh())];
    }

    /** The lobby, polled while students arrive. */
    public function show(Request $request, Competition $competition): array
    {
        $this->authorizeCompetition($request, $competition);

        return $competition->status === 'finished'
            ? ['competition' => $this->competitions->results($competition), 'finished' => true]
            : ['competition' => $this->competitions->lobby($competition), 'finished' => false];
    }

    public function start(Request $request, Competition $competition): array
    {
        $this->authorizeCompetition($request, $competition);

        return ['competition' => $this->competitions->lobby($this->competitions->start($competition))];
    }

    /** Ends the round early — latecomers keep whatever they have answered. */
    public function close(Request $request, Competition $competition): array
    {
        $this->authorizeCompetition($request, $competition);

        return ['competition' => $this->competitions->results($this->competitions->close($competition))];
    }

    public function results(Request $request, Competition $competition): array
    {
        $this->authorizeCompetition($request, $competition);

        return ['competition' => $this->competitions->results($competition)];
    }

    /**
     * Every contest the group has played, newest first — the class history.
     * A lobby or a running round is listed too, so a teacher who left the
     * screen can walk back into it.
     */
    public function index(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        return ['competitions' => $this->rows(
            Competition::where('group_id', $group->id),
        )];
    }

    /** Every contest this teacher has run, group or open — the full history. */
    public function mine(Request $request): array
    {
        $this->authorizeTeacher($request);

        return ['competitions' => $this->rows(
            Competition::where('teacher_id', $request->user()->id),
        )];
    }

    protected function rows($query): array
    {
        $competitions = $query
            ->whereIn('status', ['lobby', 'playing', 'finished'])
            ->with(['stage', 'group', 'players.user'])
            ->latest()
            ->limit(100)
            ->get();

        // A stale lobby nobody ever started is history, not an open door.
        foreach ($competitions as $competition) {
            $this->competitions->settle($competition);
        }

        return $competitions
            ->map(function (Competition $competition) {
                $winner = $competition->players
                    ->where('status', 'finished')
                    ->sortBy([['rank', 'asc'], ['score', 'desc'], ['duration_ms', 'asc']])
                    ->first();

                $expired = $competition->status === 'lobby' && $competition->expires_at?->isPast();

                return [
                    'id' => $competition->id,
                    'code' => $competition->code,
                    'status' => $competition->status,
                    'live' => $competition->isLive() && ! $expired,
                    'open' => $competition->group_id === null,
                    'group' => $competition->group?->title,
                    'group_id' => $competition->group_id,
                    'stage_id' => $competition->path_stage_id,
                    'stage' => $competition->stage?->position,
                    'stage_title' => $competition->stage?->title,
                    'questions' => $competition->questions_count,
                    'duration_minutes' => $competition->duration_minutes,
                    'participants' => $competition->players->count(),
                    'finished_players' => $competition->players->where('status', 'finished')->count(),
                    'winner' => $winner ? [
                        'name' => trim("{$winner->user?->first_name} {$winner->user?->last_name}") ?: 'Oʼquvchi',
                        'score' => $winner->score,
                        'total' => $winner->total,
                        'duration' => $this->competitions->clock((int) $winner->duration_ms),
                    ] : null,
                    'created_at' => $competition->created_at?->toIso8601String(),
                    'started_at' => $competition->started_at?->toIso8601String(),
                    'finished_at' => $competition->finished_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
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

    protected function authorizeCompetition(Request $request, Competition $competition): void
    {
        $this->authorizeTeacher($request);
        abort_unless($competition->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
