<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WordReport;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Request;

/** The flag button on a word: the player writes what is wrong, admins read it. */
class WordReportController extends Controller
{
    public function __invoke(Request $request, TelegramClient $telegram): array
    {
        $data = $request->validate([
            'word_id' => ['nullable', 'integer', 'exists:words,id'],
            'word' => ['required', 'string', 'max:120'],
            'text' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $user = $request->user();

        WordReport::create($data + ['user_id' => $user->id]);

        // An immediate receipt in the chat, so sending never feels like
        // shouting into the void. Best-effort — the report is already saved.
        if ($user->chat_id && ! $user->has_blocked_bot) {
            try {
                $telegram->sendMessage(
                    $user->chat_id,
                    "✅ Shikoyatingiz qabul qilindi.\n\n«{$data['word']}» soʼzi boʼyicha yozganingiz adminlarga yetkazildi — koʼrib chiqib, javob qaytaramiz.",
                );
            } catch (\Throwable) {
                // the receipt is a courtesy, not part of the contract
            }
        }

        return ['ok' => true];
    }
}
