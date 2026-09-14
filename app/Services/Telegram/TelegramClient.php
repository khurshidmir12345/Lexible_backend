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
        return $this->parse($method, $this->http()->post("/{$method}", $params));
    }

    /**
     * Same as call(), but streams a local file as multipart — the way a video
     * over the 20 MB URL limit gets to Telegram. Nested params (reply_markup)
     * must travel as JSON strings inside a multipart body.
     */
    public function upload(string $method, string $field, string $path, array $params = []): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = json_encode($value);
            }
        }

        $response = $this->http()
            ->timeout(180)
            ->attach($field, fopen($path, 'r'), basename($path))
            ->post("/{$method}", $params);

        return $this->parse($method, $response);
    }

    protected function parse(string $method, \Illuminate\Http\Client\Response $response): array
    {
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

    /** `$video` is a file_id, an https URL (≤ 20 MB) or a local path (≤ 50 MB, uploaded). */
    public function sendVideo(int|string $chatId, string $video, string $caption = '', array $extra = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'supports_streaming' => true,
        ], $extra);

        if (is_file($video)) {
            return $this->upload('sendVideo', 'video', $video, $params);
        }

        return $this->call('sendVideo', ['video' => $video] + $params);
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

    /** The name shown in the chat header and contact list. */
    public function setMyName(string $name, ?string $languageCode = null): array
    {
        return $this->call('setMyName', array_filter([
            'name' => $name,
            'language_code' => $languageCode,
        ]));
    }

    /** Text shown on the empty chat (“What can this bot do?”) before /start. */
    public function setMyDescription(string $description, ?string $languageCode = null): array
    {
        return $this->call('setMyDescription', array_filter([
            'description' => $description,
            'language_code' => $languageCode,
        ]));
    }

    /** Short text on the bot's profile page and in share links. */
    public function setMyShortDescription(string $description, ?string $languageCode = null): array
    {
        return $this->call('setMyShortDescription', array_filter([
            'short_description' => $description,
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
