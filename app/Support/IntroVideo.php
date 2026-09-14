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

        $fileId = self::fileId();

        // On the upload itself Telegram must be told the frame size and
        // length: without them clients draw the bubble as a small square
        // placeholder instead of a full-width player. A file_id resend
        // keeps the dimensions Telegram stored at upload time.
        $sent = $telegram->sendVideo(
            $chatId,
            $fileId ?? self::path(),
            self::caption(),
            $fileId ? $extra : array_merge(self::dimensions(), $extra),
        );

        $fileId = $sent['result']['video']['file_id'] ?? null;

        if (($sent['ok'] ?? false) && $fileId && $fileId !== self::fileId()) {
            Setting::put(self::SETTING, ['bot' => self::botId(), 'file_id' => $fileId], 'bot');
        }

        return $sent;
    }

    /**
     * width / height / duration read straight from the MP4 atoms (moov →
     * mvhd for the clock, trak → tkhd for the frame), so a replaced file is
     * described correctly without ffprobe on the server.
     *
     * @return array{width?: int, height?: int, duration?: int}
     */
    public static function dimensions(): array
    {
        $out = [];
        $fh = @fopen(self::path(), 'rb');

        if (! $fh) {
            return $out;
        }

        try {
            $moov = self::findAtom($fh, 'moov', 0, filesize(self::path()));

            if (! $moov) {
                return $out;
            }

            [$moovStart, $moovEnd] = $moov;

            if ($mvhd = self::findAtom($fh, 'mvhd', $moovStart, $moovEnd)) {
                fseek($fh, $mvhd[0]);
                $version = ord(fread($fh, 1));
                fseek($fh, $mvhd[0] + ($version === 1 ? 20 : 12));
                $u = unpack($version === 1 ? 'Nscale/Jlength' : 'Nscale/Nlength', fread($fh, $version === 1 ? 12 : 8));

                if ($u['scale'] > 0) {
                    $out['duration'] = (int) round($u['length'] / $u['scale']);
                }
            }

            $cursor = $moovStart;

            while ($trak = self::findAtom($fh, 'trak', $cursor, $moovEnd)) {
                if ($tkhd = self::findAtom($fh, 'tkhd', $trak[0], $trak[1])) {
                    fseek($fh, $tkhd[1] - 8);
                    $u = unpack('Nw/Nh', fread($fh, 8));
                    $w = $u['w'] >> 16;
                    $h = $u['h'] >> 16;

                    if ($w > 0 && $h > 0) {
                        $out['width'] = $w;
                        $out['height'] = $h;
                        break;
                    }
                }

                $cursor = $trak[1];
            }
        } finally {
            fclose($fh);
        }

        return $out;
    }

    /** Scans [$from, $to) for a top-level atom; returns [bodyStart, atomEnd] or null. */
    private static function findAtom($fh, string $type, int $from, int $to): ?array
    {
        $pos = $from;

        while ($pos + 8 <= $to) {
            fseek($fh, $pos);
            $head = fread($fh, 8);

            if (strlen($head) < 8) {
                return null;
            }

            $u = unpack('Nsize/a4type', $head);
            $size = $u['size'];
            $body = $pos + 8;

            if ($size === 1) {
                $size = unpack('J', fread($fh, 8))[1];
                $body = $pos + 16;
            } elseif ($size === 0) {
                $size = $to - $pos;
            }

            if ($size < 8) {
                return null;
            }

            if ($u['type'] === $type) {
                return [$body, $pos + $size];
            }

            $pos += $size;
        }

        return null;
    }

    private static function botId(): string
    {
        return (string) strtok((string) config('telegram.token'), ':');
    }
}
