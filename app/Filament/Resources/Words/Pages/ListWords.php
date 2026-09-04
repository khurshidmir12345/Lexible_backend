<?php

namespace App\Filament\Resources\Words\Pages;

use App\Filament\Resources\Words\WordResource;
use App\Models\Word;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListWords extends ListRecords
{
    protected static string $resource = WordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Yangi soʼz'),
        ];
    }

    /**
     * The review queue. Translations arrive from a machine translator, so the
     * job is always "check the drafts, fill the gaps" — these tabs are that
     * work list, not a generic filter.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Hammasi')
                ->badge(Word::count()),

            'review' => Tab::make('Tekshirish navbati')
                ->icon('heroicon-o-exclamation-circle')
                ->badge(Word::where('needs_review', true)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->where('needs_review', true)),

            'untranslated' => Tab::make('Tarjimasiz')
                ->icon('heroicon-o-language')
                ->badge(Word::whereNull('translations->uz')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->whereNull('translations->uz')),

            'kaa' => Tab::make('Qoraqalpoqcha yoʼq')
                ->icon('heroicon-o-pencil-square')
                ->badge(Word::whereNull('translations->kaa')->count())
                ->modifyQueryUsing(fn ($query) => $query->whereNull('translations->kaa')),

            'ready' => Tab::make('Tasdiqlangan')
                ->icon('heroicon-o-check-circle')
                ->badgeColor('success')
                ->badge(Word::where('needs_review', false)->count())
                ->modifyQueryUsing(fn ($query) => $query->where('needs_review', false)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
