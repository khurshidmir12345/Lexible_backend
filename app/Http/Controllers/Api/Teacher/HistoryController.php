<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Http\Controllers\Controller;
use App\Models\CompetitionPlayer;
use App\Models\Group;
use App\Models\TestSession;
use App\Models\User;
use App\Services\Game\CompetitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The record. Every round a student plays — a class game, an exam, a duel, a
 * practice run — is a `test_sessions` row that is never deleted, so the
 * history is a read, not a ledger kept on the side. A teacher reads any
 * student of theirs; a student reads their own.
 */
class HistoryController extends Controller
{
    public function __construct(protected CompetitionService $competitions) {}

    /** One student's record, as their teacher sees it. */
    public function student(Request $request, Group $group, User $student): array
    {
        abort_unless($request->user()->isTeacher(), Response::HTTP_FORBIDDEN, 'Bu boʼlim ustozlar uchun.');
        abort_unless($group->teacher_id === $request->user()->id, Response::HTTP_FORBIDDEN);
        abort_unless($group->students()->whereKey($student->id)->exists(), Response::HTTP_NOT_FOUND,
            'Bu oʼquvchi guruhda emas.');

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->full_name,
                'initial' => $student->initial,
                'photo' => $student->photo_url,
                'ref' => $student->studentRef(),
            ],
            'summary' => $this->summary($student),
            'sessions' => $this->sessions($student),
        ];
    }

    /** The player's own record. */
    public function mine(Request $request): array
    {
        return [
            'summary' => $this->summary($request->user()),
            'sessions' => $this->sessions($request->user()),
        ];
    }

    protected function summary(User $user): array
    {
        $finished = TestSession::where('user_id', $user->id)->where('status', 'finished');

        return [
            'sessions' => (clone $finished)->count(),
            'competitions' => (clone $finished)->whereNotNull('competition_id')->count(),
            'exams' => (clone $finished)->whereHas('category', fn ($q) => $q->where('type', 'exam'))->count(),
            'answers' => (int) (clone $finished)->sum('answered_count'),
            'correct' => (int) (clone $finished)->sum('correct_count'),
        ];
    }

    /**
     * Newest first. A competition row carries the place taken, an exam row
     * whether it was passed; the rest is the same four numbers everywhere.
     */
    protected function sessions(User $user, int $limit = 150): array
    {
        $sessions = TestSession::where('user_id', $user->id)
            ->where('status', 'finished')
            ->with(['category.pathStage', 'category.group'])
            ->latest('finished_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        $places = $this->places($sessions);
        $passMark = (int) config('game.exam.pass_mark');

        return $sessions
            ->map(function (TestSession $session) use ($places, $passMark) {
                $category = $session->category;

                $kind = match (true) {
                    (bool) $session->competition_id => 'competition',
                    (bool) $session->duel_id => 'duel',
                    $category?->type === 'exam' => 'exam',
                    default => 'practice',
                };

                // A competition paper is scored over every question on it —
                // what the clock cut off counts as wrong, as on the board.
                $over = $kind === 'competition'
                    ? max((int) $session->questions_count, (int) $session->answered_count)
                    : (int) $session->answered_count;
                $accuracy = $over > 0 ? (int) round($session->correct_count / $over * 100) : 0;

                $place = $places->get($session->id);

                return [
                    'id' => $session->id,
                    'kind' => $kind,
                    'title' => $this->title($kind, $session, $place),
                    'stage' => $category?->pathStage?->position ?? ($category?->group_id ? null : $category?->position),
                    'group' => $category?->group?->title,
                    'types' => $session->types,
                    'correct' => (int) $session->correct_count,
                    'wrong' => (int) $session->wrong_count,
                    'answered' => (int) $session->answered_count,
                    'questions' => (int) $session->questions_count,
                    'accuracy' => $accuracy,
                    'duration' => $this->competitions->clock((int) $session->duration_ms),
                    'duration_ms' => (int) $session->duration_ms,
                    'passed' => $kind === 'exam' ? $accuracy >= $passMark : null,
                    'rank' => $place['rank'] ?? null,
                    'participants' => $place['of'] ?? null,
                    'timed_out' => (bool) ($place['timed_out'] ?? false),
                    'code' => $place['code'] ?? null,
                    'finished_at' => ($session->finished_at ?? $session->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** Rank and field size for the sessions that were competition papers. */
    protected function places(Collection $sessions): Collection
    {
        $ids = $sessions->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        $players = CompetitionPlayer::whereIn('test_session_id', $ids)->with('competition.stage')->get();

        $fieldSizes = CompetitionPlayer::whereIn('competition_id', $players->pluck('competition_id')->unique())
            ->selectRaw('competition_id, COUNT(*) as total')
            ->groupBy('competition_id')
            ->pluck('total', 'competition_id');

        return $players->mapWithKeys(fn (CompetitionPlayer $player) => [$player->test_session_id => [
            'rank' => $player->rank,
            'of' => (int) ($fieldSizes[$player->competition_id] ?? 0),
            'timed_out' => $player->timed_out,
            'code' => $player->competition?->code,
            'stage_title' => $player->competition?->stage?->title,
        ]]);
    }

    protected function title(string $kind, TestSession $session, ?array $place): string
    {
        $stage = $session->category?->title ?: ($place['stage_title'] ?? null);

        return match ($kind) {
            'competition' => 'Musobaqa'.($stage ? " · {$stage}" : ''),
            'duel' => 'Duel'.($stage ? " · {$stage}" : ''),
            'exam' => $stage ?: 'Imtihon',
            default => $stage ?: 'Mashq',
        };
    }
}
