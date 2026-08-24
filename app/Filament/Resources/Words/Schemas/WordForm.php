<?php

namespace App\Filament\Resources\Words\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Soʼz')
                    ->columns(3)
                    ->schema([
                        TextInput::make('word')
                            ->label('Inglizcha soʼz')
                            ->required()
                            ->maxLength(120),
                        Select::make('part_of_speech')
                            ->label('Soʼz turkumi')
                            ->options([
                                'noun' => 'Ot (noun)',
                                'verb' => 'Feʼl (verb)',
                                'adjective' => 'Sifat (adjective)',
                                'adverb' => 'Ravish (adverb)',
                                'interjection' => 'Undov (interjection)',
                            ])
                            ->native(false),
                        TextInput::make('transcription')
                            ->label('Transkripsiya')
                            ->placeholder('/ˈbjuːtɪfəl/'),
                        TextInput::make('emoji')
                            ->label('Emoji')
                            ->maxLength(16)
                            ->helperText('3D ikonka qoʼyilmaguncha shu koʼrsatiladi'),
                        Select::make('cefr_level')
                            ->label('Daraja')
                            ->options(array_combine(
                                ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'],
                                ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'],
                            ))
                            ->native(false),
                        TextInput::make('frequency_rank')
                            ->label('Chastota oʼrni')
                            ->numeric()
                            ->helperText('Kichik son = koʼproq ishlatiladi'),
                    ]),

                Section::make('Tarjimalar')
                    ->description('Har tilga bir nechta variant yozing — testda hammasi toʼgʼri deb qabul qilinadi. Enter bosib qoʼshiladi.')
                    ->columns(2)
                    ->schema(collect(self::LANGUAGES)->map(fn ($label, $code) => TagsInput::make("translations.{$code}")
                        ->label($label)
                        ->placeholder('variant qoʼshish...')
                        ->reorderable()
                        ->columnSpan($code === 'uz' ? 2 : 1))->values()->all()),

                Section::make('Maʼno va misol')
                    ->columns(2)
                    ->schema([
                        Textarea::make('definition.en')
                            ->label('Taʼrif (inglizcha)')
                            ->rows(2),
                        Textarea::make('definition.uz')
                            ->label('Taʼrif (oʼzbekcha)')
                            ->rows(2),
                        Textarea::make('example.en')
                            ->label('Misol gap (inglizcha)')
                            ->rows(2),
                        Textarea::make('example.uz')
                            ->label('Misol gap tarjimasi')
                            ->rows(2),
                    ]),

                Section::make('Media va holat')
                    ->columns(2)
                    ->schema([
                        TextInput::make('audio_url')
                            ->label('Audio havolasi')
                            ->url()
                            ->columnSpan(2),
                        TextInput::make('icon_path')
                            ->label('3D ikonka yoʼli')
                            ->helperText('Boʼsh boʼlsa emoji ishlatiladi')
                            ->columnSpan(2),
                        Grid::make(2)->schema([
                            Toggle::make('needs_review')
                                ->label('Tekshirish kerak')
                                ->helperText('API dan kelgan maʼlumot hali tasdiqlanmagan'),
                            Toggle::make('is_active')
                                ->label('Faol')
                                ->helperText('Oʼchirilsa qidiruvda chiqmaydi'),
                        ])->columnSpan(2),
                    ]),
            ]);
    }
}
