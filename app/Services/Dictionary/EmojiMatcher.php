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

    /** @var array<string, array{0: string, 1: int, 2: bool}>|null */
    protected ?array $index = null;

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

        [$best, $score, $isFace] = $this->index()[$word] ?? [null, 0, false];

        if (! $best) {
            return null;
        }

        // A noun should not be illustrated by a face; a feeling word may be.
        if ($isFace && in_array($partOfSpeech, ['noun', 'verb'], true)) {
            $score -= 30;
        }

        // Below this the match is a coincidence, and a wrong picture teaches
        // the wrong thing — better to show nothing and let the UI fall back.
        return $score >= 40 ? $best : null;
    }

    protected function score(string $word, array $keywords): int
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

    /**
     * word => [emoji, score, isFace], built once.
     *
     * Scoring every emoji for every word is fine for the handful a teacher
     * types by hand, but the dictionary import runs it four hundred thousand
     * times — a hundred million comparisons. Inverting it into a lookup turns
     * the whole import from hours into seconds.
     *
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    protected function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $path = storage_path('app/dictionary/emoji-index.json');

        if (is_file($path)) {
            return $this->index = json_decode(file_get_contents($path), true) ?: [];
        }

        $index = [];

        foreach ($this->data() as $emoji => $keywords) {
            $isFace = (bool) array_intersect(self::FACE_HINTS, array_slice($keywords, 0, 4));

            // Every term this emoji could be looked up by. The keyword list
            // alone is not enough: 🍎 is named "red_apple" and never lists a
            // bare "apple", so the word a learner actually types has to be
            // recovered from the parts of the name.
            $terms = $keywords;

            foreach ($keywords as $keyword) {
                foreach (explode('_', (string) $keyword) as $part) {
                    $terms[] = $part;
                }
            }

            foreach (array_unique($terms) as $keyword) {
                $keyword = Str::lower(trim($keyword));

                if ($keyword === '' || str_contains($keyword, ' ')) {
                    continue;
                }

                $score = $this->score($keyword, $keywords);

                if ($score > 0 && $score > ($index[$keyword][1] ?? 0)) {
                    $index[$keyword] = [$emoji, $score, $isFace];
                }
            }
        }

        // Hand-pinned choices win outright.
        foreach ($this->overrides() as $word => $emoji) {
            $index[$word] = [$emoji, 100, false];
        }

        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($index, JSON_UNESCAPED_UNICODE));

        return $this->index = $index;
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
