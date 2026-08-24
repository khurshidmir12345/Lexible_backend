<?php

namespace App\Filament\Widgets;

use App\Models\TestAnswer;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class ActivityChart extends ChartWidget
{
    protected ?string $heading = 'Soʼnggi 14 kun — javoblar';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn ($back) => today()->subDays($back));

        // One grouped query rather than 14 counts.
        $counts = TestAnswer::query()
            ->where('created_at', '>=', $days->first())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total, SUM(is_correct) as correct')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return [
            'datasets' => [
                [
                    'label' => 'Toʼgʼri',
                    'data' => $days->map(fn (Carbon $d) => (int) ($counts[$d->toDateString()]->correct ?? 0))->all(),
                    'backgroundColor' => '#37c26a',
                    'borderColor' => '#2aa056',
                ],
                [
                    'label' => 'Xato',
                    'data' => $days->map(function (Carbon $d) use ($counts) {
                        $row = $counts[$d->toDateString()] ?? null;

                        return (int) (($row->total ?? 0) - ($row->correct ?? 0));
                    })->all(),
                    'backgroundColor' => '#e9606a',
                    'borderColor' => '#cf4651',
                ],
            ],
            'labels' => $days->map(fn (Carbon $d) => $d->format('d.m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
