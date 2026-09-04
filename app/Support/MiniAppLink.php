<?php

namespace App\Support;

/**
 * Builds a direct link into the Mini App.
 *
 * The bot's Mini App is configured as the *Main* Mini App in @BotFather, so
 * the link is `https://t.me/<bot>?startapp=<param>` — with no short name in
 * the path. Adding one (`t.me/<bot>/game?startapp=...`) makes Telegram look
 * for a separately registered app called "game" and, when it does not exist,
 * silently opens the chat instead of the game. The `startapp` value arrives
 * inside the app as `initDataUnsafe.start_param`.
 */
final class MiniAppLink
{
    public static function to(string $startParam): string
    {
        $bot = ltrim((string) config('telegram.username'), '@');

        return "https://t.me/{$bot}?startapp={$startParam}";
    }
}
