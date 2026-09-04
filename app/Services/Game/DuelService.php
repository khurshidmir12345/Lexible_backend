<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\Duel;
use App\Models\TestSession;
use App\Models\User;
use App\Models\Word;
use Illuminate\Support\Str;

/**
 * Runs a head-to-head round.
 *
 * Both players answer the identical question list, so the word set is frozen
 * when the duel is created rather than generated per player. Each side still
 * plays through an ordinary test session, which means scoring, mastery and
 * streaks all behave exactly as they do in solo play.
 */
class DuelService
{
    public function __construct(
        protected TestBuilder $builder,
        protected CoinService $coins,
        protected NotificationService $notifications,
    ) {}

    public function create(User $host, Category $category, array $types): Duel
    {
        $words = $category->words()->pluck('words.id');

        // Reload so the database defaults (scores, finished flags) are real
        // values rather than nulls on the response that follows.
        return Duel::create([
            'code' => $this->freshCode(),
            'host_id' => $host->id,
            'category_id' => $category->id,
            'types' => $types,
            'word_ids' => $words->all(),
            'status' => 'waiting',
            'expires_at' => now()->addMinutes(config('game.duel.lobby_ttl_minutes')),
        ])->fresh();
    }

    public function join(Duel $duel, User $guest): Duel
    {
        // Re-joining is harmless; a third player is not.
        if ($duel->guest_id && $duel->guest_id !== $guest->id) {
            abort(409, 'Bu duelda joy band.');
        }

        abort_if($duel->host_id === $guest->id, 409, 'Oʼzingiz bilan bellasha olmaysiz.');
        abort_if($duel->status === 'finished', 409, 'Bu duel tugagan.');
        abort_if($duel->status === 'cancelled', 410, 'Bu duel bekor qilingan.');
        abort_if($duel->status === 'waiting' && $duel->expires_at?->isPast(), 410, 'Bu taklif muddati tugagan.');

        if (! $duel->guest_id) {
            $duel->update(['guest_id' => $guest->id, 'status' => 'ready']);
        }

        return $duel->fresh();
    }

    /**
     * The caller's session for this duel, created on first call. Questions come
     * from the frozen word list so both players see the same ones.
     */
    public function session(Duel $duel, User $player): TestSession
    {
        $existing = TestSession::where('duel_id', $duel->id)
            ->where('user_id', $player->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $words = Word::whereIn('id', $duel->word_ids)->get();
        $questions = $this->builder->build($duel->category, $duel->types, $words, $player->native_lang);

        abort_if($questions === [], 422, 'Savol tuzib boʼlmadi.');

        if ($duel->status !== 'playing') {
            $duel->update(['status' => 'playing', 'started_at' => now()]);
        }

        return TestSession::create([
            'user_id' => $player->id,
            'category_id' => $duel->category_id,
            'duel_id' => $duel->id,
            'types' => $duel->types,
            'scope' => 'all',
            'status' => 'active',
            'questions_count' => count($questions),
            'payload' => $questions,
            'started_at' => now(),
        ]);
    }

    /** The host walks out of an empty lobby; the link stops working. */
    public function cancel(Duel $duel, User $host): Duel
    {
        abort_unless($duel->host_id === $host->id, 403);

        if ($duel->status === 'waiting') {
            $duel->update(['status' => 'cancelled', 'finished_at' => now()]);
        }

        return $duel->fresh();
    }

    /**
     * Records one side's finish; resolves the duel once both are in.
     *
     * The score the client sends is only a fallback: the round was played
     * through an ordinary test session, and that session's own tally is what
     * counts — so a stalled request or a tampered number cannot change it.
     */
    public function finish(Duel $duel, User $player, int $score, int $durationMs): Duel
    {
        $isHost = $duel->host_id === $player->id;

        // Finishing twice (a retried request, a reopened app) must not
        // overwrite the first result.
        if ($isHost ? $duel->host_finished : $duel->guest_finished) {
            return $duel->fresh();
        }

        $session = $this->sessionOf($duel, $player->id);

        if ($session) {
            $score = (int) $session->correct_count;
            $durationMs = (int) ($session->duration_ms ?: $durationMs);
        }

        $duel->update($isHost
            ? ['host_score' => $score, 'host_ms' => $durationMs, 'host_finished' => true]
            : ['guest_score' => $score, 'guest_ms' => $durationMs, 'guest_finished' => true]);

        $duel->refresh();

        if ($duel->host_finished && $duel->guest_finished) {
            $winner = $duel->resolveWinner();

            $duel->update([
                'status' => 'finished',
                'winner_id' => $winner,
                'finished_at' => now(),
            ]);

            if ($winner) {
                $this->coins->award($winner, config('game.coins.per_duel_win'));
            }

            $duel->loadMissing(['host', 'guest']);

            $this->notifications->duelFinished(
                $duel->host_id, $winner === $duel->host_id,
                $duel->guest?->first_name ?? 'Doʼst', $duel->host_score, $duel->guest_score,
            );

            if ($duel->guest_id) {
                $this->notifications->duelFinished(
                    $duel->guest_id, $winner === $duel->guest_id,
                    $duel->host?->first_name ?? 'Doʼst', $duel->guest_score, $duel->host_score,
                );
            }
        }

        return $duel->fresh();
    }

    /**
     * What both clients poll while the duel is in progress.
     *
     * Scores are read live from each player's test session, so the scoreboard
     * moves answer by answer; the columns on the duel row itself are only
     * written once a side finishes.
     */
    public function state(Duel $duel, User $viewer): array
    {
        $duel->loadMissing(['host', 'guest', 'category']);

        $isHost = $duel->host_id === $viewer->id;
        $me = $isHost ? $duel->host : $duel->guest;
        $rival = $isHost ? $duel->guest : $duel->host;

        $sessions = TestSession::where('duel_id', $duel->id)->get()->keyBy('user_id');
        $questions = count($duel->word_ids) * count($duel->types);

        // A lobby nobody joined in time is reported as expired rather than
        // left "waiting" forever.
        $status = $duel->status;
        if ($status === 'waiting' && $duel->expires_at?->isPast()) {
            $status = 'expired';
        }

        return [
            'code' => $duel->code,
            'status' => $status,
            'category' => $duel->category?->title,
            'types' => $duel->types,
            'questions' => $questions,
            'is_host' => $isHost,
            'invite_link' => $duel->inviteLink(),
            'expires_at' => $duel->expires_at?->toIso8601String(),
            'me' => $this->side(
                $me,
                $sessions->get($me?->id),
                $isHost ? $duel->host_score : $duel->guest_score,
                $isHost ? $duel->host_finished : $duel->guest_finished,
                $questions,
            ),
            'rival' => $rival
                ? $this->side(
                    $rival,
                    $sessions->get($rival->id),
                    $isHost ? $duel->guest_score : $duel->host_score,
                    $isHost ? $duel->guest_finished : $duel->host_finished,
                    $questions,
                )
                : null,
            'winner' => $duel->winner_id
                ? ($duel->winner_id === $viewer->id ? 'me' : 'rival')
                : ($duel->status === 'finished' ? 'draw' : null),
            'reward' => config('game.coins.per_duel_win'),
        ];
    }

    protected function side(?User $user, ?TestSession $session, ?int $score, ?bool $finished, int $questions): array
    {
        return [
            'name' => $user?->first_name ?: ($user?->full_name ?? '—'),
            'initial' => $user?->initial ?? '?',
            'photo' => $user?->photo_url,
            // Once a side has finished the duel row is authoritative; until
            // then the session's running tally is the live score.
            'score' => $finished ? (int) $score : (int) ($session?->correct_count ?? $score),
            'answered' => (int) ($session?->answered_count ?? 0),
            'total' => (int) ($session?->questions_count ?: $questions),
            'started' => $session !== null,
            'finished' => (bool) $finished,
        ];
    }

    protected function sessionOf(Duel $duel, int $userId): ?TestSession
    {
        return TestSession::where('duel_id', $duel->id)->where('user_id', $userId)->first();
    }

    /** Short, unambiguous, and easy to read out loud. */
    protected function freshCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Duel::where('code', $code)->exists());

        return $code;
    }
}
