<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Duel;
use App\Services\Game\DuelService;
use App\Services\Game\TestBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DuelController extends Controller
{
    public function __construct(
        protected DuelService $duels,
        protected TestBuilder $builder,
    ) {}

    /** Host opens a lobby for one of their categories. */
    public function store(Request $request, Category $category): array
    {
        abort_unless($category->user_id === $request->user()->id, Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'types' => ['required', 'array', 'min:1'],
            'types.*' => [Rule::in(config('game.test_types'))],
        ]);

        $min = (int) config('game.session.min_words');
        abort_if($category->words()->count() < $min, Response::HTTP_UNPROCESSABLE_ENTITY,
            "Duel uchun bosqichda kamida {$min} ta soʼz kerak.");

        $duel = $this->duels->create($request->user(), $category, $data['types']);

        return ['duel' => $this->duels->state($duel, $request->user())];
    }

    public function show(Request $request, string $code): array
    {
        $duel = $this->find($code);
        $this->authorizeViewer($request, $duel, allowJoin: true);

        return ['duel' => $this->duels->state($duel, $request->user())];
    }

    public function join(Request $request, string $code): array
    {
        $duel = $this->find($code);

        abort_if($duel->expires_at?->isPast() && $duel->status === 'waiting',
            Response::HTTP_GONE, 'Bu taklif muddati tugagan.');

        $duel = $this->duels->join($duel, $request->user());

        return ['duel' => $this->duels->state($duel, $request->user())];
    }

    /** The host closes an empty lobby; the invite link dies with it. */
    public function cancel(Request $request, string $code): array
    {
        $duel = $this->duels->cancel($this->find($code), $request->user());

        return ['duel' => $this->duels->state($duel, $request->user())];
    }

    /** Both sides call this to receive their (identical) question list. */
    public function play(Request $request, string $code): array
    {
        $duel = $this->find($code);
        $this->authorizeViewer($request, $duel);

        abort_if($duel->status === 'waiting', Response::HTTP_CONFLICT, 'Raqib hali qoʼshilmagan.');
        abort_if($duel->status === 'cancelled', Response::HTTP_GONE, 'Bu duel bekor qilingan.');
        abort_if($duel->status === 'finished', Response::HTTP_CONFLICT, 'Bu duel tugagan.');

        $session = $this->duels->session($duel, $request->user());

        return [
            'session_id' => $session->id,
            'questions' => $this->builder->forClient($session->payload),
            'duel' => $this->duels->state($duel->fresh(), $request->user()),
        ];
    }

    public function finish(Request $request, string $code): array
    {
        $duel = $this->find($code);
        $this->authorizeViewer($request, $duel);

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['required', 'integer', 'min:0'],
        ]);

        $duel = $this->duels->finish($duel, $request->user(), $data['score'], $data['duration_ms']);

        return ['duel' => $this->duels->state($duel, $request->user())];
    }

    protected function find(string $code): Duel
    {
        return Duel::where('code', strtoupper($code))->firstOrFail();
    }

    /** Only the two players may look at a duel — except when joining one. */
    protected function authorizeViewer(Request $request, Duel $duel, bool $allowJoin = false): void
    {
        $id = $request->user()->id;

        if ($duel->host_id === $id || $duel->guest_id === $id) {
            return;
        }

        abort_unless($allowJoin && $duel->status === 'waiting', Response::HTTP_FORBIDDEN);
    }
}
