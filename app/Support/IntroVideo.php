<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Telegram\TelegramClient;

/**
 * The "how to use the bot" video every first-time player receives after the
 * welcome. The file lives in public/brand; it is uploaded to Telegram once
 * (23 MB — over the URL limit, so multipart) and the returned file_id is kept
 * in settings, so every later send is a cheap file_id reference. file_ids are
 * bound to the bot that uploaded them, so the cache is keyed by bot id and a
 * token change simply re-uploads.
 */
final class IntroVideo
{
    public const SETTING = 'bot.intro_video';

    public static function path(): string
    {
        return public_path('brand/bayoz-intro.mp4');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function caption(): string
    {
        return Setting::get('bot.intro_caption') ?? implode("\n", [
            "🎬 <b>Bayoz'dan qanday foydalanish</b>",
            '',
            "Qisqa video: o'yinni ochish, so'zlarni yodlash, do'stlar bilan duel.",
        ]);
    }

    /** Cached Telegram file_id for the current bot, if the video was uploaded already. */
    public static function fileId(): ?string
    {
        $cached = Setting::get(self::SETTING);

        return is_array($cached) && ($cached['bot'] ?? null) === self::botId() ? ($cached['file_id'] ?? null) : null;
    }

    public static function forget(): void
    {
        Setting::put(self::SETTING, null, 'bot');
    }

    /**
     * Sends the video to a chat: by file_id when known, otherwise by uploading
     * the file (and remembering the file_id Telegram hands back).
     */
    public static function send(TelegramClient $telegram, int|string $chatId, array $extra = []): array
    {
        if (! self::exists()) {
            return ['ok' => false, 'description' => 'intro video file missing'];
        }

        $sent = $telegram->sendVideo($chatId, self::fileId() ?? self::path(), self::caption(), $extra);

        $fileId = $sent['result']['video']['file_id'] ?? null;

        if (($sent['ok'] ?? false) && $fileId && $fileId !== self::fileId()) {
            Setting::put(self::SETTING, ['bot' => self::botId(), 'file_id' => $fileId], 'bot');
        }

        return $sent;
    }

    private static function botId(): string
    {
        return (string) strtok((string) config('telegram.token'), ':');
    }
}
