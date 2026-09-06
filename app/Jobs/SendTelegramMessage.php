<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One bot message to one player, sent from the queue.
 *
 * Reminders go out to thousands of people at the same minute and a teacher
 * assigning a path notifies a whole class, so nothing user-facing waits on
 * Telegram. The job also owns the two facts a send can teach us: a 403 means
 * the player blocked the bot (remembered, so they are never retried), and a
 * 429 means "later", not "failed".
 */
class SendTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(
        public int $userId,
        public string $text,
        public ?array $replyMarkup = null,
    ) {}

    public function handle(TelegramClient $telegram): void
    {
        // No bot configured (tests, a bare local setup): nothing to send.
        if (blank(config('telegram.token'))) {
            return;
        }

        $user = User::find($this->userId);

        if (! $user || ! $user->chat_id || $user->has_blocked_bot || $user->is_banned) {
            return;
        }

        $extra = $this->replyMarkup ? ['reply_markup' => $this->replyMarkup] : [];
        $result = $telegram->sendMessage($user->chat_id, $this->text, $extra);

        if ($result['ok'] ?? false) {
            return;
        }

        $code = (int) ($result['error_code'] ?? 0);
        $description = strtolower((string) ($result['description'] ?? ''));

        // Blocked, deleted account, or a chat that no longer exists: stop
        // trying, and stop offering the admin a "send" button for this player.
        if ($code === 403 || str_contains($description, 'chat not found') || str_contains($description, 'deactivated')) {
            $user->update(['has_blocked_bot' => true]);

            return;
        }

        if ($code === 429 && $this->job) {
            $this->release((int) ($result['parameters']['retry_after'] ?? 30));

            return;
        }

        // Anything else (a 400 for a malformed message, a 5xx that survived
        // the client's own retries) is logged by the client; a reminder is
        // not worth a retry storm.
        Log::info('Telegram message not delivered', ['user' => $user->id, 'code' => $code, 'error' => $description]);
    }
}
