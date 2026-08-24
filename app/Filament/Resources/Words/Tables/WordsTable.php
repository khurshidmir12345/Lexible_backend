<?php

namespace App\Filament\Resources\Words\Tables;

use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use App\Services\Dictionary\EmojiMatcher;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class WordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('frequency_rank')
            ->columns([
                TextColumn::make('emoji')
                    ->label('')
                    ->size(TextSize::Large)
                    ->default('📘'),

                TextColumn::make('word')
                    ->label('Soʼz')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Word $r) => $r->transcription),

                TextColumn::make('part_of_speech')
                    ->label('Turkum')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'noun' => 'info',
                        'verb' => 'success',
                        'adjective' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'noun' => 'ot', 'verb' => 'feʼl', 'adjective' => 'sifat',
                        'adverb' => 'ravish', 'interjection' => 'undov',
                        default => $state ?? '—',
                    }),

                TextColumn::make('uz')
                    ->label('Oʼzbekcha')
                    ->state(fn (Word $r) => Str::limit(implode(', ', $r->acceptedAnswers('uz')), 34) ?: '—')
                    ->color(fn (Word $r) => $r->acceptedAnswers('uz') ? null : 'danger'),

                TextColumn::make('langs')
                    ->label('Tillar')
                    ->badge()
                    ->state(function (Word $r) {
                        // At a glance: which of the five languages are still empty.
                        return collect(['uz', 'ru', 'ky', 'kk', 'kaa'])
                            ->filter(fn ($l) => filled($r->translations[$l] ?? []))
                            ->values()
                            ->all() ?: ['yoʼq'];
                    })
                    ->color(fn (string $state) => $state === 'yoʼq' ? 'danger' : 'success'),

                IconColumn::make('audio_url')
                    ->label('Audio')
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('needs_review')
                    ->label('Tekshirilmagan')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success'),

                TextColumn::make('usage_count')
                    ->label('Qoʼshilgan')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('needs_review')
                    ->label('Tekshirish holati')
                    ->trueLabel('Tekshirilmagan')
                    ->falseLabel('Tasdiqlangan'),

                SelectFilter::make('part_of_speech')
                    ->label('Soʼz turkumi')
                    ->options([
                        'noun' => 'Ot', 'verb' => 'Feʼl', 'adjective' => 'Sifat',
                        'adverb' => 'Ravish', 'interjection' => 'Undov',
                    ]),

                Filter::make('no_translation')
                    ->label('Oʼzbekcha tarjimasi yoʼq')
                    ->query(fn ($query) => $query->whereNull('translations->uz')),

                Filter::make('no_emoji')
                    ->label('Emoji yoʼq')
                    ->query(fn ($query) => $query->whereNull('emoji')),
            ])
            ->recordActions([
                Action::make('translate')
                    ->label('Tarjima')
                    ->icon('heroicon-o-language')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('API dan tarjima olinsinmi?')
                    ->modalDescription('Faqat boʼsh tillar toʼldiriladi. Qoraqalpoq tili qoʼllab-quvvatlanmaydi.')
                    ->action(function (Word $record, DictionaryService $dictionary) {
                        $dictionary->translate($record);

                        Notification::make()
                            ->title('Tarjima yangilandi')
                            ->body('Natijani tekshirib, tasdiqlang.')
                            ->success()
                            ->send();
                    }),
                EditAction::make()->label('Tahrirlash'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve')
                        ->label('Tasdiqlash')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $count = $records->filter(fn (Word $w) => filled($w->acceptedAnswers('uz')))
                                ->each(fn (Word $w) => $w->update(['needs_review' => false]))
                                ->count();

                            $skipped = $records->count() - $count;

                            Notification::make()
                                ->title("{$count} ta soʼz tasdiqlandi")
                                ->body($skipped ? "{$skipped} tasi oʼtkazib yuborildi — oʼzbekcha tarjimasi yoʼq." : null)
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('translate')
                        ->label('Tarjima qilish')
                        ->icon('heroicon-o-language')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription('Har soʼz uchun tashqi API chaqiriladi — koʼp soʼz tanlansa sekin ishlaydi.')
                        ->action(function (Collection $records, DictionaryService $dictionary) {
                            $records->each(fn (Word $w) => $dictionary->translate($w));

                            Notification::make()
                                ->title($records->count().' ta soʼz tarjima qilindi')
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('emoji')
                        ->label('Emoji tanlash')
                        ->icon('heroicon-o-face-smile')
                        ->action(function (Collection $records, EmojiMatcher $matcher) {
                            $found = 0;

                            foreach ($records as $word) {
                                if ($emoji = $matcher->match($word->word, $word->part_of_speech)) {
                                    $word->update(['emoji' => $emoji]);
                                    $found++;
                                }
                            }

                            Notification::make()
                                ->title("{$found} ta soʼzga emoji topildi")
                                ->success()
                                ->send();
                        }),

                    DeleteBulkAction::make()->label('Oʼchirish'),
                ]),
            ]);
    }
}
