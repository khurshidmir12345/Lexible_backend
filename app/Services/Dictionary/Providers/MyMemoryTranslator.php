<?php

namespace App\Services\Dictionary\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MyMemory translation memory — free tier, no key required.
 *
 * We keep several alternatives per language rather than one string, because a
 * typed answer should be accepted in any of its usual forms ("chiroyli" and
 * "go'zal" are both right for "beautiful").
 */
class MyMemoryTranslator
{
    /** @return array<string, list<string>> e.g. ['uz' => ['chiroyli', 'goʻzal']] */
    public function translate(string $word, array $locales): array
    {
        $out = [];

        foreach ($locales as $locale) {
            $variants = $this->translateOne($word, $locale);

            if ($variants) {
                $out[$locale] = $variants;
            }

            usleep(config('dictionary.translations.delay_ms') * 1000);
        }

        return $out;
    }

    /** @return list<string> */
    public function translateOne(string $word, string $locale): array
    {
        $response = Http::timeout(config('dictionary.translations.timeout'))
            ->acceptJson()
            ->get(config('dictionary.translations.url'), array_filter([
                'q' => $word,
                'langpair' => "en|{$locale}",
                'de' => config('dictionary.translations.email'),
            ]));

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();

        // The API answers 200 with an error sentence in the body when the pair
        // is unsupported or the quota is spent.
        $primary = $body['responseData']['translatedText'] ?? null;
        if (! $primary || $this->looksLikeError($primary)) {
            return [];
        }

        return collect($body['matches'] ?? [])
            ->filter(fn ($m) => ($m['match'] ?? 0) >= config('dictionary.translations.min_match'))
            ->pluck('translation')
            ->prepend($primary)
            ->map(fn ($t) => $this->clean((string) $t))
            ->filter(fn ($t) => $this->isUsable($t, $word, $locale))
            ->unique()
            ->take(config('dictionary.translations.variants'))
            ->values()
            ->all();
    }

    protected function clean(string $text): string
    {
        // Translation memories carry stray markup and punctuation from the
        // files they were harvested from: "<x id=\"12\"/>-бет", "сайт:".
        $text = preg_replace('/<[^>]*>/', '', $text);
        $text = preg_replace('/[\\s\\p{P}]+$/u', '', $text);
        $text = preg_replace('/^[\\s\\p{P}]+/u', '', $text);

        return trim(Str::lower($text));
    }

    /**
     * MyMemory is fed by software localisation files, so a lookup can come back
     * with interface strings glued to the word ("homekeyboard label") or with
     * the English word untranslated. Neither belongs on a flashcard.
     */
    protected function isUsable(string $text, string $source, string $locale): bool
    {
        $source = Str::lower($source);

        if ($text === '' || $text === $source) {
            return false;
        }

        if ($this->looksLikeError($text) || $this->isSentence($text)) {
            return false;
        }

        // "homekeyboard label", "pastgiqshortcut" — the English word leaked in.
        if (Str::contains($text, $source)) {
            return false;
        }

        // "chiroyli / xunuk" is a dictionary entry with its antonym, not an answer.
        if (Str::contains($text, ['/', ',', ';', '|'])) {
            return false;
        }

        // A one-word prompt should not come back as a phrase ("bu go'zal").
        if (! Str::contains(trim($source), ' ') && Str::wordCount($text) > 2) {
            return false;
        }

        // Cyrillic-script targets must actually be in Cyrillic.
        if (in_array($locale, ['ru', 'kk', 'ky'], true) && ! preg_match('/\\p{Cyrillic}/u', $text)) {
            return false;
        }

        // Latin-script targets must not be plain English left-overs.
        if ($locale === 'uz' && preg_match('/\\b(label|shortcut|symbol|zodiac|keyboard|button|menu)\\b/i', $text)) {
            return false;
        }

        return true;
    }

    protected function looksLikeError(string $text): bool
    {
        return Str::contains(Str::upper($text), [
            'INVALID TARGET LANGUAGE',
            'INVALID SOURCE LANGUAGE',
            'QUERY LENGTH LIMIT',
            'MYMEMORY WARNING',
            'YOU USED ALL AVAILABLE FREE TRANSLATIONS',
        ]);
    }

    /** Translation memories often return whole sentences — useless for a word card. */
    protected function isSentence(string $text): bool
    {
        return Str::wordCount($text) > 4 || Str::contains($text, ['.', '?', '!']);
    }
}
