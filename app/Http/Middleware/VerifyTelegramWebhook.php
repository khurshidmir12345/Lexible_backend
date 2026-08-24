<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram echoes the secret we registered with setWebhook on every update.
 * Without this check anyone who guesses the URL could feed us fake updates.
 */
class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('telegram.webhook.secret');

        if (filled($expected) && ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        return $next($request);
    }
}
