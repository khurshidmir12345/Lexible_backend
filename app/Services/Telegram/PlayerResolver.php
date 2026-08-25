<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Turns a Telegram user payload (from initData or a webhook update) into a
 * persisted player, keeping the profile fields fresh on every visit.
 */
class PlayerResolver
{
    public function resolve(array $tgUser, ?int $chatId = null, ?string $startPayload = null): User
    {
        $user = User::firstOrNew(['telegram_id' => $tgUser['id']]);
        $isNew = ! $user->exists;

        $user->fill([
            'username' => $tgUser['username'] ?? null,
            'first_name' => $tgUser['first_name'] ?? null,
            'last_name' => $tgUser['last_name'] ?? null,
            'photo_url' => $tgUser['photo_url'] ?? $user->photo_url,
            'is_telegram_premium' => (bool) ($tgUser['is_premium'] ?? false),
            'allows_write_to_pm' => (bool) ($tgUser['allows_write_to_pm'] ?? $user->allows_write_to_pm ?? true),
            'last_seen_at' => now(),
        ]);

        if ($chatId) {
            $user->chat_id = $chatId;
        }

        if ($isNew) {
            $user->chat_id ??= $tgUser['id'];
            // Telegram's own language is the best first guess; onboarding confirms it.
            $user->native_lang = $this->supportedLocale($tgUser['language_code'] ?? null);
            $this->applyStartPayload($user, $startPayload);
        }

        // Coming back after blocking the bot clears the flag.
        if ($user->has_blocked_bot) {
            $user->has_blocked_bot = false;
        }

        $user->save();

        // Reload so the database defaults (streak, goal, flags) are populated
        // rather than null on the very first request.
        if ($isNew) {
            $user->refresh();
        }

        if ($isNew && $user->referred_by) {
            User::whereKey($user->referred_by)->increment('referrals_count');
            app(\App\Services\Game\CoinService::class)
                ->award($user->referred_by, config('game.coins.per_referral'));
        }

        return $user;
    }

    /** `?start=ref_12345` / `?startapp=ref_12345` links a new player to their inviter. */
    protected function applyStartPayload(User $user, ?string $payload): void
    {
        if (blank($payload)) {
            return;
        }

        $user->source = Str::limit($payload, 190, '');

        if (Str::startsWith($payload, 'ref_')) {
            $referrerTelegramId = (int) Str::after($payload, 'ref_');

            if ($referrerTelegramId && $referrerTelegramId !== (int) $user->telegram_id) {
                $user->referred_by = User::where('telegram_id', $referrerTelegramId)->value('id');
            }
        }
    }

    protected function supportedLocale(?string $code): string
    {
        $code = Str::lower(Str::before((string) $code, '-'));

        return in_array($code, config('app.supported_locales', ['uz', 'ru', 'en']), true) ? $code : 'uz';
    }
}
