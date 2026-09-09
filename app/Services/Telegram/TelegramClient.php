<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Bot API. Deliberately dependency-free: every call is
 * `$telegram->call('methodName', [...])`, with helpers for what we use often.
 */
class TelegramClient
{
    public function __construct(
        protected ?string $token = null,
        protected ?string $apiUrl = null,
    ) {
        $this->token ??= config('telegram.token');
        $this->apiUrl ??= config('telegram.api_url');
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->apiUrl, '/')."/bot{$this->token}")
            ->timeout(15)
            ->retry(2, 300, throw: false)
            ->acceptJson();
    }

    /**
     * @return array{ok: bool, result?: mixed, description?: string, error_code?: int}
     */
    public function call(string $method, array $params = []): array
    {
        $response = $this->http()->post("/{$method}", $params);
        $body = $response->json() ?? [];

        if (! ($body['ok'] ?? false)) {
            Log::warning('Telegram API error', [
                'method' => $method,
                'error' => $body['description'] ?? $response->body(),
                'code' => $body['error_code'] ?? $response->status(),
            ]);
        }

        return $body;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ], $extra));
    }

    public function sendPhoto(int|string $chatId, string $photo, string $caption = '', array $extra = []): array
    {
        return $this->call('sendPhoto', array_merge([
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ], $extra));
    }

    /**
     * Stores a message a Mini App user may then share into any chat with
     * `WebApp.shareMessage(id)` — the way a teacher drops a result card into
     * the class group without leaving the app.
     *
     * @param  array<string, mixed>  $result  an InlineQueryResult
     */
    public function savePreparedInlineMessage(int $userId, array $result, bool $groups = true, bool $channels = true): array
    {
        return $this->call('savePreparedInlineMessage', [
            'user_id' => $userId,
            'result' => $result,
            'allow_user_chats' => true,
            'allow_bot_chats' => false,
            'allow_group_chats' => $groups,
            'allow_channel_chats' => $channels,
        ]);
    }

    public function answerCallbackQuery(string $id, string $text = '', bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    public function setWebhook(string $url, ?string $secret = null, array $allowedUpdates = []): array
    {
        return $this->call('setWebhook', array_filter([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => $allowedUpdates ?: null,
            'drop_pending_updates' => true,
            'max_connections' => 40,
        ]));
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => true]);
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function setMyCommands(array $commands, ?string $languageCode = null): array
    {
        return $this->call('setMyCommands', array_filter([
            'commands' => $commands,
            'language_code' => $languageCode,
        ]));
    }

    public function setChatMenuButton(string $text, string $webAppUrl): array
    {
        return $this->call('setChatMenuButton', [
            'menu_button' => [
                'type' => 'web_app',
                'text' => $text,
                'web_app' => ['url' => $webAppUrl],
            ],
        ]);
    }

    /** File served from Telegram's CDN, e.g. a user photo or voice note. */
    public function fileUrl(string $filePath): string
    {
        return rtrim($this->apiUrl, '/')."/file/bot{$this->token}/{$filePath}";
    }
}
