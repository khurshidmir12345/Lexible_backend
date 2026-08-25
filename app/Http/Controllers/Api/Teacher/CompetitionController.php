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

    /** Everything the teacher sees on a stage: the class, and its weak spots. */
    public function index(Request $request, Group $group): array
    {
        $this->authorizeOwner($request, $group);

        $rows = Competition::where('group_id', $group->id)
            ->whereIn('status', ['lobby', 'playing', 'finished'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Competition $competition) => [
                'code' => $competition->code,
                'status' => $competition->status,
                'stage' => $competition->stage?->position,
                'participants' => $competition->players()->count(),
                'created_at' => $competition->created_at?->toIso8601String(),
            ]);

        return ['competitions' => $rows];
    }

    protected function authorizeOwner(Request $request, Group $group): void
    {
        abort_unless($request->user()->role === 'teacher', Response::HTTP_FORBIDDEN);
        abort_unless($group->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }

    protected function authorizeCompetition(Request $request, Competition $competition): void
    {
        abort_unless($request->user()->role === 'teacher', Response::HTTP_FORBIDDEN);
        abort_unless($competition->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
    }
}
