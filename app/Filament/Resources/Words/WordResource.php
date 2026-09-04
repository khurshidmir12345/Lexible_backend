<?php

namespace App\Filament\Resources\Words;

use App\Filament\Resources\Words\Pages\CreateWord;
use App\Filament\Resources\Words\Pages\EditWord;
use App\Filament\Resources\Words\Pages\ListWords;
use App\Filament\Resources\Words\Schemas\WordForm;
use App\Filament\Resources\Words\Tables\WordsTable;
use App\Models\Icon;
use App\Models\Word;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WordResource extends Resource
{
    protected static ?string $model = Word::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Lugʼat';

    protected static ?string $navigationLabel = 'Soʼzlar';

    protected static ?string $modelLabel = 'soʼz';

    protected static ?string $pluralModelLabel = 'soʼzlar';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'word';

    /** Unreviewed words are work waiting to be done — show the count in the sidebar. */
    public static function getNavigationBadge(): ?string
    {
        $pending = static::getModel()::where('needs_review', true)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return WordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WordsTable::configure($table);
    }

    /**
     * An icon chosen in the form is an admin's decision: it is stored as
     * `manual` so the automatic matcher never overwrites it, and `icon_path`
     * (what the API serves) follows the chosen icon. Removing the icon is a
     * decision too — the word stays without a picture.
     */
    public static function applyIcon(array $data, ?int $previousIconId): array
    {
        $iconId = isset($data['icon_id']) ? (int) $data['icon_id'] : null;

        if ($iconId === $previousIconId) {
            return $data;
        }

        $icon = $iconId ? Icon::find($iconId) : null;

        $data['icon_id'] = $icon?->id;
        $data['icon_path'] = $icon ? Icon::pathFor($icon->slug) : null;
        $data['icon_source'] = 'manual';
        $data['icon_confidence'] = $icon ? 100 : null;

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWords::route('/'),
            'create' => CreateWord::route('/create'),
            'edit' => EditWord::route('/{record}/edit'),
        ];
    }
}
