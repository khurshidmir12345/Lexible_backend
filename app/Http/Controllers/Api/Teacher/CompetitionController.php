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
        ]);

        $stage = PathStage::findOrFail($data['path_stage_id']);

        $competition = $this->competitions->create(
            $request->user(), $group, $stage, $data['types'] ?? null,
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
        ]);

        $group = null;

        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            $this->authorizeOwner($request, $group);
        }

        $competition = $this->competitions->create(
            $request->user(), $group, $stage, $data['types'] ?? null,
        );

        return ['competition' => $this->competitions->lobby($competition)];
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

    /** Recent contests, so the teacher can reopen a board they closed. */
    public function index(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        return ['competitions' => $this->rows(
            Competition::where('group_id', $group->id),
        )];
    }

    /** Every contest this teacher has run, group or open — UT-WEB's list. */
    public function mine(Request $request): array
    {
        $this->authorizeTeacher($request);

        return ['competitions' => $this->rows(
            Competition::where('teacher_id', $request->user()->id),
        )];
    }

    protected function rows($query): array
    {
        return $query
            ->whereIn('status', ['lobby', 'playing', 'finished'])
            ->with(['stage', 'group'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Competition $competition) => [
                'id' => $competition->id,
                'code' => $competition->code,
                'status' => $competition->status,
                'open' => $competition->group_id === null,
                'group' => $competition->group?->title,
                'stage' => $competition->stage?->position,
                'stage_title' => $competition->stage?->title,
                'participants' => $competition->players()->count(),
                'created_at' => $competition->created_at?->toIso8601String(),
            ])
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
