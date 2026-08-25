<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Game\CompetitionService;
use App\Services\Game\TestBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The student side of a contest: join, wait, play, see the board. */
class CompetitionController extends Controller
{
    public function __construct(
        protected CompetitionService $competitions,
        protected TestBuilder $builder,
    ) {}

    public function show(Request $request, string $code): array
    {
        $competition = $this->find($code);

        return ['competition' => $this->competitions->studentState($competition, $request->user())];
    }

    public function join(Request $request, string $code): array
    {
        $competition = $this->find($code);

        abort_if($competition->expires_at?->isPast() && $competition->status === 'lobby',
            Response::HTTP_GONE, 'Bu musobaqa muddati tugagan.');

        $this->competitions->join($competition, $request->user());

        return ['competition' => $this->competitions->studentState($competition->fresh(), $request->user())];
    }

    /** Hands over the question paper once the teacher has started the round. */
    public function session(Request $request, string $code): array
    {
        $competition = $this->find($code);
        $session = $this->competitions->session($competition, $request->user());

        return [
            'session_id' => $session->id,
            'questions' => $this->builder->forClient($session->payload),
            'competition' => $this->competitions->studentState($competition->fresh(), $request->user()),
        ];
    }

    public function finish(Request $request, string $code): array
    {
        $competition = $this->find($code);

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['required', 'integer', 'min:0'],
        ]);

        $competition = $this->competitions->finish(
            $competition, $request->user(), $data['score'], $data['total'], $data['duration_ms'],
        );

        return ['competition' => $this->competitions->studentState($competition, $request->user())];
    }

    public function results(Request $request, string $code): array
    {
        $competition = $this->find($code);

        abort_unless(
            $competition->players()->where('user_id', $request->user()->id)->exists(),
            Response::HTTP_FORBIDDEN,
        );

        return ['competition' => $this->competitions->results($competition)];
    }

    protected function find(string $code): Competition
    {
        return Competition::where('code', strtoupper($code))->firstOrFail();
    }
}
