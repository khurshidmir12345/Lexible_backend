<?php

namespace App\Services\Game;

use Illuminate\Support\Facades\Storage;

/**
 * Draws a competition's final board as a picture — the thing a teacher posts
 * in the class group so everyone sees who came where.
 *
 * Plain GD on purpose: no browser, no queue, a few hundred milliseconds.
 * 1080×1350 (4:5) is the portrait Telegram shows largest in a chat.
 */
class ResultCard
{
    public const W = 1080;

    public const H = 1350;

    protected string $bold;

    protected string $regular;

    public function __construct()
    {
        $this->bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $this->regular = resource_path('fonts/DejaVuSans.ttf');
    }

    /**
     * Renders the board and returns the public-disk path of the PNG. The name
     * carries a hash of the standings, so a board that has not changed is
     * not drawn twice and an old picture is never served for a new result.
     *
     * @param  array<string, mixed>  $board  CompetitionService::results()
     */
    public function render(array $board): string
    {
        $path = sprintf('share/comp-%s-%s.png', strtolower($board['code']), substr(md5(json_encode(collect($board['standings'] ?? [])->all())), 0, 10));

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        $png = $this->draw($board);
        Storage::disk('public')->put($path, $png, 'public');

        return $path;
    }

    /** @param array<string, mixed> $board */
    protected function draw(array $board): string
    {
        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $c = fn (int $r, int $g, int $b, int $a = 0) => imagecolorallocatealpha($im, $r, $g, $b, $a);

        $paper = $c(0xF0, 0xF3, 0xF0);
        $ink = $c(0x16, 0x20, 0x1A);
        $muted = $c(0x66, 0x73, 0x6B);
        $white = $c(0xFF, 0xFF, 0xFF);
        $green = $c(0x17, 0xA4, 0x5C);
        $goldInk = $c(0x6B, 0x4E, 0x00);
        $line = $c(0xE5, 0xEA, 0xE5);

        imagefilledrectangle($im, 0, 0, self::W, self::H, $paper);

        // Header: a green gradient band, the way the app's top bar reads.
        $headH = 300;
        for ($y = 0; $y < $headH; $y++) {
            $t = $y / $headH;
            imageline($im, 0, $y, self::W, $y, $c(
                (int) round(0x14 + (0x17 - 0x14) * $t),
                (int) round(0x8A + (0xA4 - 0x8A) * $t),
                (int) round(0x4C + (0x5C - 0x4C) * $t),
            ));
        }

        $this->text($im, 'LEXIBLE', 56, 96, 26, $c(0xFF, 0xFF, 0xFF, 50), $this->bold, 6);
        $this->text($im, $this->status($board), 56, 176, 52, $white, $this->bold);

        $sub = [];
        if (! empty($board['stage'])) {
            $sub[] = $board['stage'].'-bosqich';
        }
        $sub[] = ($board['questions'] ?? 0).' savol';
        $sub[] = ($board['participants'] ?? 0).' ishtirokchi';
        $this->text($im, implode('  ·  ', $sub), 56, 236, 28, $c(0xFF, 0xFF, 0xFF, 25), $this->regular);

        $this->textRight($im, $this->fit($board['group'] ?? '', 26), self::W - 56, 96, 26, $c(0xFF, 0xFF, 0xFF, 40), $this->bold);

        $standings = collect($board['standings'] ?? [])->values()->all();
        $podium = array_slice($standings, 0, 3);
        $rest = array_slice($standings, 3, 7);

        // Podium: 2 · 1 · 3, the winner's card taller.
        $slots = [
            ['place' => 2, 'x' => 56, 'h' => 350, 'top' => $c(0xE9, 0xEE, 0xF3), 'bar' => $c(0x9A, 0xA8, 0xB5), 'inkOn' => $ink],
            ['place' => 1, 'x' => 380, 'h' => 390, 'top' => $c(0xFF, 0xF4, 0xD0), 'bar' => $c(0xE3, 0xB2, 0x3B), 'inkOn' => $goldInk],
            ['place' => 3, 'x' => 704, 'h' => 330, 'top' => $c(0xF6, 0xEA, 0xE0), 'bar' => $c(0xC9, 0x8E, 0x5C), 'inkOn' => $ink],
        ];
        $podiumBase = 800;

        foreach ($slots as $slot) {
            $player = collect($podium)->firstWhere('rank', $slot['place']) ?? ($podium[$slot['place'] - 1] ?? null);
            if (! $player) {
                continue;
            }

            $w = 320;
            $x = $slot['x'];
            $y = $podiumBase - $slot['h'];

            $this->roundedRect($im, $x, $y, $x + $w, $podiumBase, 28, $white);
            $this->roundedRect($im, $x, $podiumBase - 64, $x + $w, $podiumBase, 28, $slot['bar']);
            imagefilledrectangle($im, $x, $podiumBase - 64, $x + $w, $podiumBase - 28, $slot['bar']);
            $this->textCenter($im, (string) $slot['place'], $x + $w / 2, $podiumBase - 18, 34, $white, $this->bold);

            $cx = (int) ($x + $w / 2);
            $this->avatar($im, $cx, $y + 86, $slot['place'] === 1 ? 64 : 54, $player['name'], $slot['top'], $slot['inkOn']);

            $this->textCenter($im, $this->fit($this->firstName($player['name']), 14), $cx, $y + 190, 32, $ink, $this->bold);
            $this->textCenter($im, "{$player['score']} toʼgʼri · {$player['accuracy']}%", $cx, $y + 228, 22, $muted, $this->regular);
        }

        // The rest of the field.
        $y = $podiumBase + 44;
        foreach ($rest as $row) {
            $this->roundedRect($im, 56, $y, self::W - 56, $y + 72, 18, $white);
            $this->text($im, (string) $row['rank'], 84, $y + 48, 28, $muted, $this->bold);
            $this->avatar($im, 170, $y + 36, 26, $row['name'], $c(0xE9, 0xF7, 0xEF), $green);
            $this->text($im, $this->fit($row['name'], 30), 216, $y + 46, 26, $ink, $this->bold);
            $verdict = ! empty($row['finished']) ? "{$row['score']} toʼgʼri · {$row['accuracy']}%" : 'tugatmadi';
            $this->textRight($im, $verdict, self::W - 84, $y + 46, 24, $muted, $this->regular);
            $y += 84;
        }

        if (! $standings) {
            $this->textCenter($im, 'Hali natija yoʼq', self::W / 2, 700, 30, $muted, $this->regular);
        }

        // Footer
        imageline($im, 56, self::H - 96, self::W - 56, self::H - 96, $line);
        $this->text($im, 'lexible.uz — ingliz tilini oʼyin bilan', 56, self::H - 48, 22, $muted, $this->regular);
        $this->textRight($im, '@'.ltrim((string) config('telegram.username'), '@'), self::W - 56, self::H - 48, 22, $green, $this->bold);

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $board */
    protected function status(array $board): string
    {
        return ($board['status'] ?? '') === 'finished' ? 'Musobaqa yakunlandi' : 'Natijalar toʼplanmoqda';
    }

    protected function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?: $name;
    }

    protected function fit(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    protected function avatar(\GdImage $im, int $cx, int $cy, int $r, string $name, int $fill, int $ink): void
    {
        imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $fill);
        $this->textCenter($im, mb_strtoupper(mb_substr(trim($name), 0, 1) ?: '?'), $cx, $cy + (int) ($r * 0.42), (int) ($r * 1.1), $ink, $this->bold);
    }

    protected function roundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    protected function text(\GdImage $im, string $s, int $x, int $y, int $size, int $color, string $font, int $spacing = 0): void
    {
        if ($spacing) {
            foreach (mb_str_split($s) as $ch) {
                imagettftext($im, $size, 0, $x, $y, $color, $font, $ch);
                $box = imagettfbbox($size, 0, $font, $ch);
                $x += ($box[2] - $box[0]) + $spacing;
            }

            return;
        }

        imagettftext($im, $size, 0, $x, $y, $color, $font, $s);
    }

    protected function width(string $s, int $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $s);

        return $box[2] - $box[0];
    }

    protected function textCenter(\GdImage $im, string $s, int|float $cx, int $y, int $size, int $color, string $font): void
    {
        imagettftext($im, $size, 0, (int) ($cx - $this->width($s, $size, $font) / 2), $y, $color, $font, $s);
    }

    protected function textRight(\GdImage $im, string $s, int $right, int $y, int $size, int $color, string $font): void
    {
        imagettftext($im, $size, 0, $right - $this->width($s, $size, $font), $y, $color, $font, $s);
    }
}
