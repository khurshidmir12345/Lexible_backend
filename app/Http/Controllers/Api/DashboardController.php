<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Duel;
use App\Models\TestAnswer;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): array
    {
        $user = $request->user();

        $weekStart = today()->startOfWeek();

        // Correct answers per weekday, Monday first — the bar chart on the home tab.
        $perDay = TestAnswer::where('user_id', $user->id)
            ->where('is_correct', true)
            ->where('created_at', '>=', $weekStart)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $week = collect(range(0, 6))
            ->map(fn (int $offset) => (int) ($perDay[$weekStart->copy()->addDays($offset)->toDateString()] ?? 0));

        $monthCount = TestAnswer::where('user_id', $user->id)
            ->where('is_correct', true)
            ->where('created_at', '>=', today()->startOfMonth())
            ->count();

        $wins = Duel::where('winner_id', $user->id)->count();
        $played = Duel::where('status', 'finished')
            ->where(fn ($q) => $q->where('host_id', $user->id)->orWhere('guest_id', $user->id))
            ->count();

        return [
            'name' => $user->first_name ?: $user->full_name,
            'streak_days' => $user->streak_days,
            'words_learned' => $user->words_learned,
            'week' => $week->all(),
            'week_total' => $week->sum(),
            'month_total' => $monthCount,
            // Same projection the prototype showed: current weekly pace over 90 days.
            'projection_90d' => $user->words_learned + (int) round($week->sum() / 7 * 90),
            'duel' => [
                'wins' => $wins,
                'losses' => max(0, $played - $wins),
                'win_rate' => $played > 0 ? (int) round($wins / $played * 100) : 0,
            ],
        ];
    }
}
