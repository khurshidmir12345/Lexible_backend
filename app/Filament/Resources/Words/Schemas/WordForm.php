<?php

namespace App\Filament\Resources\Words\Schemas;

use App\Models\Icon;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an admin edits on a word: the word itself, its translations and its
 * picture. Definitions and examples sit in a collapsed section; there are no
 * URL fields — audio is generated and the icon comes from the library.
 */
class WordForm
{
    /** Language code => label, in the order the app offers them. */
    public const LANGUAGES = [
        'uz' => 'Oʼzbekcha',
        'ru' => 'Ruscha',
        'ky' => 'Qirgʼizcha',
        'kk' => 'Qozoqcha',
        'kaa' => 'Qoraqalpoqcha',
    ];

    public const PARTS_OF_SPEECH = [
        'noun' => 'Ot (noun)',
        'verb' => 'Feʼl (verb)',
        'adjective' => 'Sifat (adjective)',
        'adverb' => 'Ravish (adverb)',
        'pronoun' => 'Olmosh (pronoun)',
        'preposition' => 'Koʼmakchi (preposition)',
        'conjunction' => 'Bogʼlovchi (conjunction)',
        'interjection' => 'Undov (interjection)',
        'numeral' => 'Son (numeral)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Soʼz')
                    ->columns(4)
                    ->schema([
                        TextInput::make('word')
                            ->label('Inglizcha soʼz')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->columnSpan(2),
                        Select::make('part_of_speech')
                            ->label('Soʼz turkumi')
                            ->options(self::PARTS_OF_SPEECH)
                            ->native(false),
                        Select::make('cefr_level')
                            ->label('Daraja')
                            ->options(array_combine(['A1', 'A2', 'B1', 'B2', 'C1', 'C2'], ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']))
                            ->native(false),
                        TextInput::make('transcription')
                            ->label('Transkripsiya')
                            ->placeholder('/ˈbjuːtɪfəl/')
                            ->columnSpan(2),
                        TextInput::make('frequency_rank')
                            ->label('Chastota oʼrni')
                            ->numeric()
                            ->helperText('Kichik son = koʼproq ishlatiladi'),
                        TextInput::make('emoji')
                            ->label('Emoji (zaxira)')
                            ->maxLength(16)
                            ->helperText('Ikonka boʼlmasa koʼrsatiladi'),
                    ]),

                Section::make('Tarjimalar')
                    ->description('Bir tilga bir nechta variant yozish mumkin — testda hammasi toʼgʼri deb qabul qilinadi. Har variantdan keyin Enter.')
                    ->columns(2)
                    ->schema(collect(self::LANGUAGES)->map(fn ($label, $code) => TagsInput::make("translations.{$code}")
                        ->label($label)
                        ->placeholder('variant qoʼshish…')
                        ->reorderable()
                        ->columnSpan($code === 'uz' ? 2 : 1))->values()->all()),

                Section::make('Ikonka')
                    ->description('3D kutubxonadan rasm: qidiruvdan yoki takliflardan tanlang. Tanlangan rasm «admin tanlagan» deb saqlanadi va avtomatika uni oʼzgartirmaydi.')
                    ->schema([
                        Select::make('icon_id')
                            ->label('Kutubxonadan qidirish')
                            ->placeholder('Ikonka nomini yozing: apple, hand wave, school…')
                            ->searchable()
                            ->live()
                            ->allowHtml()
                            ->searchDebounce(300)
                            ->getSearchResultsUsing(fn (string $search) => self::searchIcons($search))
                            ->getOptionLabelUsing(fn ($value) => ($icon = Icon::find($value)) ? self::optionHtml($icon) : null)
                            ->helperText('Inglizcha nom yoki teg boʼyicha, 10 000 ta ikonka.'),
                        ViewField::make('icon_id')
                            ->label('')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->view('filament.forms.components.icon-picker'),
                    ]),

                Section::make('Taʼrif va misol')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Textarea::make('definition.en')->label('Taʼrif (inglizcha)')->rows(2),
                        Textarea::make('definition.uz')->label('Taʼrif (oʼzbekcha)')->rows(2),
                        Textarea::make('example.en')->label('Misol gap (inglizcha)')->rows(2),
                        Textarea::make('example.uz')->label('Misol gap tarjimasi')->rows(2),
                    ]),

                Section::make('Holat')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Faol')
                            ->default(true)
                            ->helperText('Oʼchirilsa qidiruvda va oʼyinlarda chiqmaydi'),
                        Toggle::make('needs_review')
                            ->label('Tekshirish kerak')
                            ->default(false)
                            ->helperText('Yoqilgan boʼlsa «Tekshirish navbati»da turadi'),
                    ]),
            ]);
    }

    /** @return array<int, string> id => option html */
    public static function searchIcons(string $search): array
    {
        $term = mb_strtolower(trim($search));

        if ($term === '') {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return Icon::query()
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('tags', 'like', $like))
            ->orderByRaw('CASE WHEN LOWER(title) = ? THEN 0 WHEN LOWER(title) LIKE ? THEN 1 ELSE 2 END', [$term, addcslashes($term, '%_\\').'%'])
            ->orderByRaw('LENGTH(title)')
            ->limit(40)
            ->get()
            ->mapWithKeys(fn (Icon $icon) => [$icon->id => self::optionHtml($icon)])
            ->all();
    }

    public static function optionHtml(Icon $icon): string
    {
        return sprintf(
            '<span style="display:inline-flex;align-items:center;gap:10px"><img src="%s" alt="" style="width:34px;height:34px;object-fit:contain"><span>%s <span style="opacity:.55;font-size:12px">· %s</span></span></span>',
            e($icon->url(256)),
            e($icon->title),
            e($icon->category),
        );
    }
}
