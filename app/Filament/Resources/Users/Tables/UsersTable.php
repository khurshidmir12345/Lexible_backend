<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Ism')
                    ->searchable(['first_name', 'last_name', 'username'])
                    ->weight('bold')
                    ->description(fn (User $r) => $r->username ? '@'.$r->username : "id: {$r->telegram_id}"),

                TextColumn::make('native_lang')
                    ->label('Til')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => [
                        'uz' => 'Oʼzbek', 'ru' => 'Rus', 'ky' => 'Qirgʼiz',
                        'kk' => 'Qozoq', 'kaa' => 'Qoraqalpoq',
                    ][$state] ?? $state),

                TextColumn::make('cefr_level')
                    ->label('Daraja')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('streak_days')
                    ->label('🔥 Seriya')
                    ->sortable()
                    ->formatStateUsing(fn (int $state) => $state.' kun'),

                TextColumn::make('words_learned')
                    ->label('Yodlangan')
                    ->sortable(),

                TextColumn::make('daily_goal')
                    ->label('Maqsad')
                    ->formatStateUsing(fn (int $state) => $state.' soʼz/kun')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('onboarded')
                    ->label('Sozlagan')
                    ->boolean(),

                TextColumn::make('last_seen_at')
                    ->label('Oxirgi faollik')
                    ->since()
                    ->sortable()
                    ->placeholder('—'),

                IconColumn::make('has_blocked_bot')
                    ->label('Botni bloklagan')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('native_lang')
                    ->label('Ona tili')
                    ->options([
                        'uz' => 'Oʼzbek', 'ru' => 'Rus', 'ky' => 'Qirgʼiz',
                        'kk' => 'Qozoq', 'kaa' => 'Qoraqalpoq',
                    ]),

                TernaryFilter::make('onboarded')->label('Sozlashni tugatgan'),
                TernaryFilter::make('is_banned')->label('Bloklangan'),
                TernaryFilter::make('has_blocked_bot')->label('Botni bloklagan'),

                Filter::make('active_today')
                    ->label('Bugun faol')
                    ->query(fn ($query) => $query->whereDate('last_seen_at', today())),
            ])
            ->recordActions([
                Action::make('message')
                    ->label('Xabar')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    // A player who blocked the bot cannot receive anything.
                    ->hidden(fn (User $record) => $record->has_blocked_bot || ! $record->chat_id)
                    ->schema([
                        Textarea::make('text')
                            ->label('Xabar matni')
                            ->required()
                            ->rows(4),
                    ])
                    ->action(function (User $record, array $data, TelegramClient $telegram) {
                        $result = $telegram->sendMessage($record->chat_id, $data['text']);

                        if ($result['ok'] ?? false) {
                            Notification::make()->title('Xabar yuborildi')->success()->send();

                            return;
                        }

                        // 403 from Telegram means the player blocked us — record it.
                        if (($result['error_code'] ?? null) === 403) {
                            $record->update(['has_blocked_bot' => true]);
                        }

                        Notification::make()
                            ->title('Yuborilmadi')
                            ->body($result['description'] ?? 'Nomaʼlum xatolik')
                            ->danger()
                            ->send();
                    }),

                Action::make('ban')
                    ->label(fn (User $record) => $record->is_banned ? 'Blokdan chiqarish' : 'Bloklash')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (User $record) => $record->is_banned ? 'gray' : 'danger')
                    ->requiresConfirmation()
                    ->action(fn (User $record) => $record->update(['is_banned' => ! $record->is_banned])),
            ]);
    }
}
