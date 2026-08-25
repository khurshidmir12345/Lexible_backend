<?php

namespace App\Services\Game;

use App\Models\User;

/**
 * Coins and the Premium days they unlock.
 *
 * Every award goes through here so the lifetime total — the number the
 * Premium tiers are measured against — can never drift from the balance.
 */
class CoinService
{
    public function award(User|int $user, int $amount): User
    {
        $user = $user instanceof User ? $user : User::findOrFail($user);

        if ($amount <= 0) {
            return $user;
        }

        User::whereKey($user->id)->increment('coins', $amount);
        User::whereKey($user->id)->increment('coins_lifetime', $amount);

        return $this->grantEarnedTiers($user->refresh());
    }

    /** Opens any Premium tier the player has now passed. */
    public function grantEarnedTiers(User $user): User
    {
        $tiers = config('game.coins.premium_tiers');
        $granted = 0;
        $days = 0;

        foreach ($tiers as $index => $tier) {
            if ($index < $user->premium_tier) {
                continue;   // already collected
            }

            if ($user->coins_lifetime < $tier['coins']) {
                break;      // tiers are ordered, so nothing further qualifies
            }

            $granted = $index + 1;
            $days += $tier['days'];
        }

        if (! $days) {
            return $user;
        }

        // Extending an active subscription adds to it rather than restarting.
        $from = $user->premium_until?->isFuture() ? $user->premium_until : now();

        $user->update([
            'premium_tier' => $granted,
            'premium_until' => $from->copy()->addDays($days),
        ]);

        return $user->refresh();
    }

    /** What the coins sheet shows. */
    public function summary(User $user): array
    {
        $tiers = config('game.coins.premium_tiers');
        $next = $tiers[$user->premium_tier] ?? null;
        $previous = $user->premium_tier > 0 ? $tiers[$user->premium_tier - 1]['coins'] : 0;

        return [
            'balance' => $user->coins,
            'lifetime' => $user->coins_lifetime,
            'rules' => [
                ['emoji' => '✅', 'label' => 'Har bajarilgan mashq', 'value' => '+'.config('game.coins.per_correct')],
                ['emoji' => '📖', 'label' => 'Soʼz toʼliq oʼzlashtirilganda (6/6)', 'value' => '+'.config('game.coins.per_word_mastered')],
                ['emoji' => '⚔️', 'label' => 'Duel gʼalabasi (1 ga 1)', 'value' => '+'.config('game.coins.per_duel_win')],
                ['emoji' => '👥', 'label' => 'Guruhaviy duel gʼalabasi', 'value' => '+'.config('game.coins.per_group_duel_win')],
                ['emoji' => '🎁', 'label' => 'Taklif qilingan doʼst', 'value' => '+'.config('game.coins.per_referral')],
            ],
            'premium' => [
                'active' => (bool) $user->premium_until?->isFuture(),
                'until' => $user->premium_until?->toDateString(),
                'tier' => $user->premium_tier,
                'next_at' => $next['coins'] ?? null,
                'next_days' => $next['days'] ?? null,
                'remaining' => $next ? max(0, $next['coins'] - $user->coins_lifetime) : 0,
                'progress' => $next
                    ? (int) round(min(1, max(0, ($user->coins_lifetime - $previous) / max(1, $next['coins'] - $previous))) * 100)
                    : 100,
                'tiers' => collect($tiers)->map(fn ($tier, $index) => [
                    'coins' => $tier['coins'],
                    'days' => $tier['days'],
                    'unlocked' => $index < $user->premium_tier,
                ])->all(),
            ],
        ];
    }
}
