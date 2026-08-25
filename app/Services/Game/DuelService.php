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
    public function __construct(protected TestBuilder $builder) {}

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

    /** Records one side's finish; resolves the duel once both are in. */
    public function finish(Duel $duel, User $player, int $score, int $durationMs): Duel
    {
        $isHost = $duel->host_id === $player->id;

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
                User::whereKey($winner)->increment('coins', config('game.coins.per_duel_win'));
            }
        }

        return $duel->fresh();
    }

    /** What both clients poll while the duel is in progress. */
    public function state(Duel $duel, User $viewer): array
    {
        $duel->loadMissing(['host', 'guest', 'category']);

        $isHost = $duel->host_id === $viewer->id;
        $me = $isHost ? $duel->host : $duel->guest;
        $rival = $isHost ? $duel->guest : $duel->host;

        return [
            'code' => $duel->code,
            'status' => $duel->status,
            'category' => $duel->category?->title,
            'types' => $duel->types,
            'questions' => count($duel->word_ids) * count($duel->types),
            'is_host' => $isHost,
            'invite_link' => $duel->inviteLink(),
            'me' => $this->side($me, $isHost ? $duel->host_score : $duel->guest_score, $isHost ? $duel->host_finished : $duel->guest_finished),
            'rival' => $rival
                ? $this->side($rival, $isHost ? $duel->guest_score : $duel->host_score, $isHost ? $duel->guest_finished : $duel->host_finished)
                : null,
            'winner' => $duel->winner_id
                ? ($duel->winner_id === $viewer->id ? 'me' : 'rival')
                : ($duel->status === 'finished' ? 'draw' : null),
            'reward' => config('game.coins.per_duel_win'),
        ];
    }

    protected function side(?User $user, ?int $score, ?bool $finished): array
    {
        return [
            'name' => $user?->first_name ?: ($user?->full_name ?? '—'),
            'initial' => $user?->initial ?? '?',
            'photo' => $user?->photo_url,
            'score' => (int) $score,
            'finished' => (bool) $finished,
        ];
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
