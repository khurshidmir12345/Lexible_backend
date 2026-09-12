<?php

namespace App\Support;

use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;

/**
 * Builds a direct link into the Mini App.
 *
 * With the *Main* Mini App enabled in @BotFather the link is
 * `https://t.me/<bot>?startapp=<param>` — no short name in the path. Adding
 * one (`t.me/<bot>/game?startapp=...`) makes Telegram look for a separately
 * registered app called "game" and, when it does not exist, silently opens
 * the chat instead of the game. The `startapp` value arrives inside the app
 * as `initDataUnsafe.start_param`.
 *
 * Until the Main Mini App is switched on (a manual BotFather step after every
 * bot change) a `startapp` link only opens the chat and the parameter is
 * lost. So the builder asks the Bot API once every few minutes whether the
 * app is enabled and otherwise falls back to `?start=<param>`: that lands in
 * the chat as `/start <param>`, and the bot answers with a web_app button that
 * carries the same parameter into the game (UpdateHandler::playKeyboard).
 */
final class MiniAppLink
{
    private const CACHE_KEY = 'telegram.has_main_web_app';

    public static function to(string $startParam): string
    {
        $bot = ltrim((string) config('telegram.username'), '@');
        $key = self::hasMainApp() ? 'startapp' : 'start';

        return "https://t.me/{$bot}?{$key}={$startParam}";
    }

    /** Whether the bot's Main Mini App is enabled (TELEGRAM_MAIN_MINI_APP overrides auto-detection). */
    public static function hasMainApp(): bool
    {
        $configured = config('telegram.mini_app.main');

        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        if (blank(config('telegram.token'))) {
            return true;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return (bool) $cached;
        }

        $me = app(TelegramClient::class)->getMe();

        if (! ($me['ok'] ?? false)) {
            // Unknown state: the `start` form works either way.
            return false;
        }

        $enabled = (bool) ($me['result']['has_main_web_app'] ?? false);
        Cache::put(self::CACHE_KEY, $enabled, now()->addMinutes(10));

        return $enabled;
    }

    public static function refresh(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
