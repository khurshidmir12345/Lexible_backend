<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TestAnswer;
use App\Services\Game\CoinService;
use Illuminate\Http\Request;

class CoinController extends Controller
{
    /** The coins sheet: balance, how it is earned, and the Premium ladder. */
    public function show(Request $request, CoinService $coins): array
    {
        return $coins->summary($request->user());
    }

    /** The streak sheet: this week's days, records and totals. */
    public function streak(Request $request): array
    {
        $user = $request->user();
        $weekStart = today()->startOfWeek();

        $days = TestAnswer::where('user_id', $user->id)
            ->where('created_at', '>=', $weekStart)
            ->selectRaw('DATE(created_at) as day')
            ->distinct()
            ->pluck('day')
            ->flip();

        $activeDays = TestAnswer::where('user_id', $user->id)
            ->selectRaw('COUNT(DISTINCT DATE(created_at)) as total')
            ->value('total');

        return [
            'streak_days' => $user->streak_days,
            'best_streak' => $user->best_streak,
            'active_days' => (int) $activeDays,
            'since' => $user->created_at?->translatedFormat('j F'),
            'week' => collect(range(0, 6))
                ->map(fn (int $offset) => $days->has($weekStart->copy()->addDays($offset)->toDateString()))
                ->all(),
        ];
    }
}
