<?php

namespace App\Filament\Resources\WordReports\Tables;

use App\Models\WordReport;
use App\Services\Telegram\TelegramClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WordReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('word')
                    ->label('Soʼz')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (WordReport $r) => $r->word_id ? "soʼz ID: {$r->word_id}" : 'soʼz oʼchirilgan'),

                TextColumn::make('text')
                    ->label('Shikoyat')
                    ->searchable()
                    ->wrap()
                    ->limit(160),

                TextColumn::make('user.full_name')
                    ->label('Kimdan')
                    ->description(fn (WordReport $r) => $r->user?->username ? '@'.$r->user->username : null)
                    ->placeholder('—'),

                TextColumn::make('reply')
                    ->label('Javob')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('Hali javob berilmagan')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Qachon')
                    ->since()
                    ->sortable(),

                IconColumn::make('resolved')
                    ->label('Hal boʼldi')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('resolved')
                    ->label('Hal boʼldi')
                    ->placeholder('Hammasi')
                    ->trueLabel('Hal boʼlganlar')
                    ->falseLabel('Kutayotganlar'),
            ])
            ->recordActions([
                Action::make('reply')
                    ->label('Javob berish')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    // A player who blocked the bot cannot receive anything.
                    ->hidden(fn (WordReport $r) => ! $r->user?->chat_id || $r->user->has_blocked_bot)
                    ->schema([
                        Textarea::make('reply')
                            ->label('Javob matni — bot orqali yuboriladi')
                            ->required()
                            ->maxLength(500)
                            ->rows(4),
                    ])
                    ->action(function (WordReport $record, array $data, TelegramClient $telegram) {
                        $result = $telegram->sendMessage(
                            $record->user->chat_id,
                            "💬 «{$record->word}» soʼzi boʼyicha shikoyatingizga javob:\n\n{$data['reply']}",
                        );

                        if ($result['ok'] ?? false) {
                            $record->update(['reply' => $data['reply'], 'resolved' => true]);
                            Notification::make()->title('Javob yuborildi')->success()->send();

                            return;
                        }

                        if (($result['error_code'] ?? null) === 403) {
                            $record->user->update(['has_blocked_bot' => true]);
                        }

                        Notification::make()
                            ->title('Yuborilmadi')
                            ->body($result['description'] ?? 'Nomaʼlum xatolik')
                            ->danger()
                            ->send();
                    }),

                Action::make('resolve')
                    ->label('Hal boʼldi')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->hidden(fn (WordReport $r) => $r->resolved)
                    ->requiresConfirmation()
                    ->action(fn (WordReport $record) => $record->update(['resolved' => true])),
            ]);
    }
}
