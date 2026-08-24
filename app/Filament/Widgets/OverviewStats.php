<?php

namespace App\Filament\Widgets;

use App\Models\TestAnswer;
use App\Models\User;
use App\Models\Word;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $players = User::count();
        $activeToday = User::whereDate('last_seen_at', today())->count();
        $newThisWeek = User::where('created_at', '>=', now()->subWeek())->count();

        $words = Word::count();
        $pending = Word::where('needs_review', true)->count();
        $untranslated = Word::whereNull('translations->uz')->count();

        $answersToday = TestAnswer::whereDate('created_at', today())->count();
        $correctToday = TestAnswer::whereDate('created_at', today())->where('is_correct', true)->count();
        $accuracy = $answersToday > 0 ? round($correctToday / $answersToday * 100) : 0;

        return [
            Stat::make('Oʼyinchilar', number_format($players))
                ->description($newThisWeek.' tasi shu hafta qoʼshildi')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('Bugun faol', number_format($activeToday))
                ->description($players > 0 ? round($activeToday / $players * 100).'% oʼyinchilar' : '—')
                ->color('info'),

            Stat::make('Lugʼatdagi soʼzlar', number_format($words))
                ->description($untranslated > 0 ? $untranslated.' tasi tarjimasiz' : 'hammasi tarjima qilingan')
                ->descriptionIcon($untranslated > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($untranslated > 0 ? 'warning' : 'success'),

            Stat::make('Tekshirish navbati', number_format($pending))
                ->description($pending > 0 ? 'tasdiqlanishi kutilmoqda' : 'navbat boʼsh')
                ->color($pending > 0 ? 'warning' : 'success'),

            Stat::make('Bugungi javoblar', number_format($answersToday))
                ->description($answersToday > 0 ? $accuracy.'% toʼgʼri' : 'hali javob yoʼq')
                ->color($accuracy >= 70 ? 'success' : ($answersToday > 0 ? 'warning' : 'gray')),
        ];
    }
}
