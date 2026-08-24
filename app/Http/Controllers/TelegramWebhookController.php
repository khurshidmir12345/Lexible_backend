<?php

namespace App\Http\Controllers;

use App\Models\TelegramUpdate;
use App\Services\Telegram\UpdateHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, UpdateHandler $handler): Response
    {
        $update = $request->all();

        $log = config('telegram.webhook.log_updates')
            ? TelegramUpdate::create([
                'update_id' => $update['update_id'] ?? 0,
                'type' => array_key_first(array_diff_key($update, ['update_id' => null])) ?: null,
                'telegram_id' => data_get($update, 'message.from.id')
                    ?? data_get($update, 'callback_query.from.id')
                    ?? data_get($update, 'my_chat_member.from.id'),
                'payload' => $update,
                'created_at' => now(),
            ])
            : null;

        try {
            $handler->handle($update);
            $log?->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            // Never 500 back at Telegram: it would retry the same update forever.
            Log::error('Telegram update failed', ['error' => $e->getMessage(), 'update' => $update]);
            $log?->update(['error' => $e->getMessage(), 'processed_at' => now()]);
        }

        return response('', 200);
    }
}
