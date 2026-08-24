<?php

namespace App\Services\Dictionary;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Picks the emoji that best illustrates an English word.
 *
 * The dataset (emojilib, MIT) maps each emoji to a list of keywords whose first
 * entry is its canonical name. A naive "is the word anywhere in the keywords"
 * search fails badly — "apple" appears in the keywords of ⌚ because of Apple
 * Watch — so matches are scored, with the name weighing far more than a
 * trailing keyword.
 *
 * This is the free stand-in for the 3D icons: `words.icon_path` stays empty
 * until we have them, and the UI falls back to this emoji.
 */
class EmojiMatcher
{
    protected const SOURCE = 'https://raw.githubusercontent.com/muan/emojilib/main/dist/emoji-en-US.json';

    /** @var array<string, list<string>>|null */
    protected ?array $data = null;

    /** @var array<string, string>|null */
    protected ?array $overrides = null;

    /** Emoji that describe a feeling; only right when the word is one too. */
    protected const FACE_HINTS = ['face', 'smiley', 'emotion', 'person', 'gesture'];

    public function match(string $word, ?string $partOfSpeech = null): ?string
    {
        $word = Str::lower(trim($word));

        if ($word === '') {
            return null;
        }

        // Scoring cannot tell "car" (🚗) from "railway_car" (🚃), so frequent
        // words that it gets wrong are pinned by hand.
        if ($pinned = $this->overrides()[$word] ?? null) {
            return $pinned;
        }

        $best = null;
        $bestScore = 0;

        foreach ($this->data() as $emoji => $keywords) {
            $score = $this->score($word, $keywords, $partOfSpeech);

            if ($score > $bestScore) {
                $best = $emoji;
                $bestScore = $score;
            }
        }

        // Below this the match is a coincidence, and a wrong picture teaches
        // the wrong thing — better to show nothing and let the UI fall back.
        return $bestScore >= 40 ? $best : null;
    }

    protected function score(string $word, array $keywords, ?string $partOfSpeech): int
    {
        $name = $keywords[0] ?? '';
        $tokens = explode('_', $name);

        $score = match (true) {
            $name === $word => 100,
            $name === $word.'s' => 95,                       // "books" for "book"
            $tokens[count($tokens) - 1] === $word => 80,     // "red_apple" for "apple"
            in_array($word, $tokens, true) => 70,            // "water_wave" for "water"
            default => 0,
        };

        if ($score === 0) {
            $index = array_search($word, array_slice($keywords, 1), true);

            if ($index === false) {
                return 0;
            }

            // Later keywords are weaker associations, so they score lower.
            $score = max(0, 45 - $index * 5);
        }

        // A noun should not be illustrated by a face; a feeling word may be.
        $isFace = (bool) array_intersect(self::FACE_HINTS, array_slice($keywords, 0, 4));
        if ($isFace && in_array($partOfSpeech, ['noun', 'verb'], true)) {
            $score -= 30;
        }

        // Shorter names are the more generic, more recognisable emoji.
        $score -= min(count($tokens) - 1, 3) * 2;

        return $score;
    }

    /** @return array<string, string> */
    protected function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $path = storage_path('app/dictionary/emoji-overrides.json');
        $map = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];

        unset($map['_comment']);

        return $this->overrides = $map;
    }

    /** @return array<string, list<string>> */
    protected function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = storage_path('app/dictionary/emoji-keywords.json');

        if (! is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, Http::timeout(30)->get(self::SOURCE)->body());
        }

        return $this->data = json_decode(file_get_contents($path), true) ?: [];
    }
}
