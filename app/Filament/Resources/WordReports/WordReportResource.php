<?php

namespace App\Filament\Resources\WordReports;

use App\Filament\Resources\WordReports\Pages\ListWordReports;
use App\Filament\Resources\WordReports\Tables\WordReportsTable;
use App\Models\WordReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WordReportResource extends Resource
{
    protected static ?string $model = WordReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Lugʼat';

    protected static ?string $navigationLabel = 'Shikoyatlar';

    protected static ?string $modelLabel = 'shikoyat';

    protected static ?string $pluralModelLabel = 'shikoyatlar';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'word';

    /** Unanswered complaints are work waiting to be done. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('resolved', false)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return WordReportsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWordReports::route('/'),
        ];
    }
}
