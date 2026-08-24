<?php

namespace App\Http\Middleware;

use App\Services\Telegram\InitDataValidator;
use App\Services\Telegram\InvalidInitDataException;
use App\Services\Telegram\PlayerResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless auth for Mini App API calls: the client sends the raw initData
 * string in `X-Telegram-Init-Data` and we re-verify the signature on every
 * request. No tokens to issue, store, refresh or leak.
 */
class AuthenticateMiniApp
{
    public function __construct(
        protected InitDataValidator $validator,
        protected PlayerResolver $players,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->header('X-Telegram-Init-Data') ?: $request->input('_auth');

        // Local development without Telegram: TELEGRAM_DEV_USER_ID acts as the player.
        if (blank($initData) && app()->environment('local') && config('telegram.dev_user_id')) {
            $user = $this->players->resolve([
                'id' => config('telegram.dev_user_id'),
                'first_name' => 'Dev',
                'username' => 'dev_player',
            ]);
            $request->setUserResolver(fn () => $user);

            return $next($request);
        }

        try {
            $payload = $this->validator->validate($initData);
        } catch (InvalidInitDataException $e) {
            return response()->json([
                'message' => 'Telegram authentication failed.',
                'reason' => $e->getMessage(),
            ], 401);
        }

        $user = $this->players->resolve(
            $payload['user'],
            $payload['chat']['id'] ?? null,
            $payload['start_param'] ?? null,
        );

        if ($user->is_banned) {
            return response()->json(['message' => 'Bu hisob bloklangan.'], 403);
        }

        $request->setUserResolver(fn () => $user);
        app()->setLocale($user->native_lang);

        return $next($request);
    }
}
