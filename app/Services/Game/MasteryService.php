<?php

namespace App\Services\Game;

use App\Models\TestAnswer;
use App\Models\TestSession;
use App\Models\User;
use App\Models\Word;
use App\Models\WordProgress;

/**
 * Keeps the six mastery dimensions, the daily streak and the learned-word
 * counter in step with what the player just answered.
 */
class MasteryService
{
    public function record(TestSession $session, array $question, mixed $given, bool $isCorrect, int $responseMs): WordProgress
    {
        $wordId = $question['word_id'] ?? null;

        $progress = WordProgress::firstOrNew([
            'user_id' => $session->user_id,
            'word_id' => $wordId,
        ]);

        $progress->first_seen_at ??= now();

        $column = 'm_'.$question['type'];
        $current = (int) ($progress->{$column} ?? 0);

        $progress->{$column} = $isCorrect
            ? min(config('game.mastery.max'), $current + config('game.mastery.gain_on_correct'))
            : max(0, $current - config('game.mastery.loss_on_wrong'));

        $isCorrect ? $progress->correct_count++ : $progress->wrong_count++;
        $progress->last_practiced_at = now();

        // A brand new row has no `is_learned` attribute yet, so it must be read
        // as false rather than null — otherwise the very first answer looks
        // like a word that stopped being learned.
        $wasLearned = (bool) $progress->is_learned;
        $progress->recalculate();
        $progress->save();

        // The counter only moves when a word crosses the threshold, in either
        // direction, so it stays accurate as mastery decays.
        if ($progress->is_learned !== $wasLearned) {
            $this->adjustLearnedCount($session->user_id, $progress->is_learned);
        }

        if ($isCorrect) {
            User::whereKey($session->user_id)->increment('coins', config('game.coins.per_correct'));
        }

        TestAnswer::create([
            'test_session_id' => $session->id,
            'user_id' => $session->user_id,
            'word_id' => $wordId,
            'type' => $question['type'],
            'is_correct' => $isCorrect,
            'given_answer' => is_scalar($given) ? (string) $given : null,
            'response_ms' => $responseMs,
            'created_at' => now(),
        ]);

        Word::whereKey($wordId)->increment('usage_count');

        return $progress;
    }

    /**
     * Moves the player's learned-word counter by one, atomically and never
     * below zero — the column is unsigned, and MySQL rejects an underflow
     * outright rather than clamping it.
     */
    protected function adjustLearnedCount(int $userId, bool $learned): void
    {
        $query = User::whereKey($userId);

        if ($learned) {
            $query->increment('words_learned');

            return;
        }

        $query->where('words_learned', '>', 0)->decrement('words_learned');
    }

    /**
     * A streak counts consecutive days with at least one finished round.
     * Practising twice in one day does not advance it; missing a day resets it.
     */
    public function touchStreak(User $user): void
    {
        $today = today();

        if ($user->last_practiced_date?->isSameDay($today)) {
            return;
        }

        $continues = $user->last_practiced_date?->isSameDay($today->copy()->subDay()) ?? false;

        $user->streak_days = $continues ? $user->streak_days + 1 : 1;
        $user->best_streak = max($user->best_streak, $user->streak_days);
        $user->last_practiced_date = $today;
        $user->save();
    }
}
