<?php

namespace App\Filament\Resources\Words\Tables;

use App\Models\Word;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The word list an admin works in: picture, word, translation, picture status.
 *
 * Search is by prefix on the word itself (typing "go" must not drown "go" in
 * "gone" and "goal"), and the exact hit is pinned to the first row.
 */
class WordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('frequency_rank')
            ->modifyQueryUsing(function (Builder $query, $livewire) {
                $term = mb_strtolower(trim((string) ($livewire->getTableSearch() ?? '')));

                if ($term !== '') {
                    $query->orderByRaw('CASE WHEN normalized = ? THEN 0 ELSE 1 END', [$term]);
                }

                return $query->orderByRaw('CASE WHEN frequency_rank IS NULL THEN 1 ELSE 0 END');
            })
            ->searchPlaceholder('Soʼzni yozing: go, apple, hello…')
            ->columns([
                ViewColumn::make('picture')
                    ->label('')
                    ->view('filament.tables.columns.word-picture'),

                TextColumn::make('word')
                    ->label('Soʼz')
                    ->searchable(query: function (Builder $query, string $search) {
                        $term = mb_strtolower(trim($search));
                        $prefix = addcslashes($term, '%_\\').'%';

                        // The word itself by prefix, or a translation that starts with the term.
                        return $query->where(fn (Builder $q) => $q
                            ->where('normalized', 'like', $prefix)
                            ->orWhere('translations', 'like', '%"'.addcslashes($term, '%_\\').'%'));
                    })
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Word $r) => $r->transcription),

                TextColumn::make('uz')
                    ->label('Oʼzbekcha')
                    ->state(fn (Word $r) => Str::limit(implode(', ', $r->acceptedAnswers('uz')), 40) ?: '—')
                    ->color(fn (Word $r) => $r->acceptedAnswers('uz') ? null : 'danger'),

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
                        'adverb' => 'ravish', 'interjection' => 'undov', 'pronoun' => 'olmosh',
                        'preposition' => 'koʼmakchi', 'conjunction' => 'bogʼlovchi', 'numeral' => 'son',
                        default => $state ?? '—',
                    }),

                TextColumn::make('icon_state')
                    ->label('Ikonka')
                    ->badge()
                    ->state(fn (Word $r) => match (true) {
                        $r->icon_source === 'manual' && $r->icon_id => 'admin',
                        $r->icon_source === 'manual' => 'rasm yoʼq (admin)',
                        (bool) $r->icon_id => 'avto '.($r->icon_confidence ?? '').'%',
                        default => 'yoʼq',
                    })
                    ->color(fn (Word $r) => match (true) {
                        $r->icon_source === 'manual' => 'success',
                        (bool) $r->icon_id && $r->icon_confidence >= 85 => 'info',
                        (bool) $r->icon_id => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('frequency_rank')
                    ->label('Oʼrin')
                    ->sortable()
                    ->color('gray'),

                TextColumn::make('langs')
                    ->label('Tillar')
                    ->badge()
                    ->state(fn (Word $r) => collect(['uz', 'ru', 'ky', 'kk', 'kaa'])
                        ->filter(fn ($l) => filled($r->translations[$l] ?? []))
                        ->values()
                        ->all() ?: ['yoʼq'])
                    ->color(fn (string $state) => $state === 'yoʼq' ? 'danger' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('needs_review')
                    ->label('Tekshirilmagan')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('icon')
                    ->label('Ikonka')
                    ->options([
                        'manual' => 'Admin tanlagan',
                        'auto' => 'Avtomatik',
                        'none' => 'Ikonkasiz',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'manual' => $query->where('icon_source', 'manual')->whereNotNull('icon_id'),
                        'auto' => $query->whereNotNull('icon_id')->where(fn ($q) => $q->whereNull('icon_source')->orWhere('icon_source', '!=', 'manual')),
                        'none' => $query->whereNull('icon_id'),
                        default => $query,
                    }),

                SelectFilter::make('part_of_speech')
                    ->label('Soʼz turkumi')
                    ->options([
                        'noun' => 'Ot', 'verb' => 'Feʼl', 'adjective' => 'Sifat',
                        'adverb' => 'Ravish', 'interjection' => 'Undov',
                    ]),

                Filter::make('no_translation')
                    ->label('Oʼzbekcha tarjimasi yoʼq')
                    ->query(fn ($query) => $query->whereNull('translations->uz')),

                TernaryFilter::make('needs_review')
                    ->label('Tekshirish holati')
                    ->trueLabel('Tekshirilmagan')
                    ->falseLabel('Tasdiqlangan'),
            ])
            ->recordActions([
                EditAction::make()->label('Tahrirlash'),
                DeleteAction::make()->label('Oʼchirish'),
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

                    DeleteBulkAction::make()->label('Oʼchirish'),
                ]),
            ]);
    }
}
