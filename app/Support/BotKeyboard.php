<?php

namespace App\Support;

/**
 * Inline keyboards the bot attaches to its messages.
 *
 * Every proactive message ends in one tap into the Mini App, so the button
 * is built in one place: the brand-green "play" button (Bot API 9.4 `style`;
 * older clients ignore the colour), optionally carrying a start parameter
 * the app reads as `start_param` — a duel or competition code, a stage.
 */
class BotKeyboard
{
    public static function play(string $label = "🎮 O'ynash", ?string $startParam = null, string $style = 'success'): array
    {
        $url = config('telegram.mini_app.url');

        if ($startParam) {
            $url .= (str_contains($url, '?') ? '&' : '?').'startapp='.$startParam;
        }

        return ['inline_keyboard' => [[[
            'text' => $label,
            'web_app' => ['url' => $url],
            'style' => $style,
        ]]]];
    }

    public static function link(string $label, string $url, string $style = 'primary'): array
    {
        return ['inline_keyboard' => [[[
            'text' => $label,
            'url' => $url,
            'style' => $style,
        ]]]];
    }
}
