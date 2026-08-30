<?php

namespace App\Services\Dictionary\Providers;

use App\Services\Dictionary\Contracts\Translator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Translates dictionary entries with Google's Gemini API.
 *
 * Chosen for the bulk of the dictionary because it is roughly a tenth of the
 * price of the alternatives and, measured on the words that actually trip a
 * translator up — "felt" the cloth, "well" the hole in the ground, "spoke" the
 * part of a wheel — it got 19 of 20 right.
 *
 * The one failure mode to know about: it occasionally invents a plausible
 * looking second translation. That is survivable here because the first form
 * is what a card displays and the rest are only *accepted* answers, so a bogus
 * extra makes grading slightly more generous rather than wrong. Anything
 * Cyrillic, over-long, or not asked for is dropped outright.
 */
class GeminiTranslator implements Translator
{
    /** Language names the model knows, keyed by the locale we store under. */
    protected const LANGUAGES = [
        'uz' => 'Uzbek (Latin script)',
        'ru' => 'Russian',
        'kk' => 'Kazakh (Cyrillic script)',
        'ky' => 'Kyrgyz (Cyrillic script)',
        'kaa' => 'Karakalpak (Latin script)',
    ];

    /** Locales written in Cyrillic, where a Cyrillic answer is correct. */
    protected const CYRILLIC = ['ru', 'kk', 'ky'];

    public function name(): string
    {
        return 'gemini';
    }

    /**
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     * @param  list<string>  $locales
     * @return array<string, array<string, list<string>>>
     */
    public function translate(array $words, array $locales = ['uz']): array
    {
        if ($words === [] || $locales === []) {
            return [];
        }

        $unknown = array_diff($locales, array_keys(self::LANGUAGES));

        if ($unknown) {
            throw new RuntimeException('Notanish til: '.implode(', ', $unknown));
        }

        return $this->parse($this->send($words, $locales), $words, $locales);
    }

    /** The one place that talks to the network. */
    protected function send(array $words, array $locales): string
    {
        $model = config('dictionary.gemini.model');
        $key = config('dictionary.gemini.key');

        if (! $key) {
            throw new RuntimeException('GEMINI_API_KEY oʼrnatilmagan — .env fayliga qoʼshing.');
        }

        $response = Http::timeout(config('dictionary.gemini.timeout'))
            // A bulk run brushes against the per-minute quota constantly, and
            // a 429 means "wait", not "give up" — so back off and keep going
            // rather than parking eighty words as failed.
            ->retry(
                config('dictionary.gemini.retries'),
                fn (int $attempt) => $attempt * config('dictionary.gemini.backoff_ms'),
                fn ($e, $request) => $e instanceof \Illuminate\Http\Client\ConnectionException
                    || in_array($e->response?->status(), [408, 429, 500, 502, 503, 504], true),
                throw: false,
            )
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                [
                    'system_instruction' => ['parts' => [['text' => $this->instructions($locales)]]],
                    'contents' => [['parts' => [['text' => $this->payload($words)]]]],
                    'generationConfig' => [
                        // Translation is not a place for creativity, and a
                        // fixed temperature makes a re-run reproducible.
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'maxOutputTokens' => config('dictionary.gemini.max_tokens'),
                    ],
                ],
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Gemini '.$response->status().': '.mb_substr($response->body(), 0, 200),
            );
        }

        $body = $response->json();

        if (isset($body['error'])) {
            throw new RuntimeException('Gemini: '.($body['error']['message'] ?? 'nomaʼlum xato'));
        }

        $text = '';

        foreach ($body['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        if (trim($text) === '') {
            $reason = $body['candidates'][0]['finishReason'] ?? '?';

            throw new RuntimeException("Gemini boʼsh javob qaytardi (finishReason: {$reason})");
        }

        return $text;
    }

    /** @param list<string> $locales */
    protected function instructions(array $locales): string
    {
        $names = array_map(fn (string $l) => self::LANGUAGES[$l], $locales);
        $shape = collect($locales)
            ->mapWithKeys(fn (string $l) => [$l => ['…']])
            ->all();

        $example = json_encode(
            ['home' => collect($locales)->mapWithKeys(fn ($l) => [
                $l => $l === 'uz' ? ['uy', 'turar joy'] : ['…'],
            ])->all()],
            JSON_UNESCAPED_UNICODE,
        );

        return <<<TXT
        You translate English vocabulary for a language-learning app used by
        schoolchildren in Uzbekistan. Target languages: {$this->join($names)}.

        Each entry gives the English word, its part of speech, its English
        definition and sometimes an example sentence. Translate the word **as
        used in that definition** — not as it might appear in a software menu,
        a brand name, or a different part of speech.

        Rules:
        - Uzbek and Karakalpak in the Latin alphabet, modern orthography: oʻ
          and gʻ carry the ʻ mark, and ʼ where an apostrophe belongs. Never
          Cyrillic for those two. Russian, Kazakh and Kyrgyz in Cyrillic.
        - Give the plain dictionary form: a noun singular, a verb in the
          infinitive (-moq for Uzbek), an adjective in its base form.
        - 1 to 3 translations per language, best first. Add a second only when
          it is genuinely used for the same sense. **Never invent a word** — if
          you are unsure of a second form, give only the first.
        - Translate the word, not the definition.
        - No explanations, no transliteration of the English, no brackets.
        - If an entry has no real equivalent, give the borrowed form actual
          speakers use. If you truly cannot translate it, give an empty array.

        Reply with JSON only: an object mapping each English word to an object
        keyed by language code. No prose, no markdown fence.

        Shape: {"<english word>": {$this->shape($shape)}}
        Example: {$example}
        TXT;
    }

    protected function shape(array $shape): string
    {
        return json_encode($shape, JSON_UNESCAPED_UNICODE);
    }

    protected function join(array $names): string
    {
        return count($names) === 1
            ? $names[0]
            : implode(', ', array_slice($names, 0, -1)).' and '.end($names);
    }

    /** @param list<array{word: string, pos: ?string, gloss: ?string, example: ?string}> $words */
    public function payload(array $words): string
    {
        $lines = [];

        foreach ($words as $entry) {
            $line = $entry['word'];

            if (! empty($entry['pos'])) {
                $line .= ' ('.$entry['pos'].')';
            }

            if (! empty($entry['gloss'])) {
                $line .= ' — '.$this->shorten($entry['gloss'], 200);
            }

            if (! empty($entry['example'])) {
                $line .= ' | e.g. '.$this->shorten($entry['example'], 110);
            }

            $lines[] = $line;
        }

        return 'Translate these '.count($words)." entries:\n\n".implode("\n", $lines);
    }

    /**
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     * @param  list<string>  $locales
     * @return array<string, array<string, list<string>>>
     */
    public function parse(string $text, array $words, array $locales = ['uz']): array
    {
        $decoded = json_decode($this->unfence($text), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini javobini oʼqib boʼlmadi: '.mb_substr($text, 0, 200));
        }

        // Only words we asked about — an invented extra entry must never reach
        // the dictionary.
        $asked = array_flip(array_column($words, 'word'));
        $out = [];

        foreach ($decoded as $word => $byLocale) {
            if (! isset($asked[$word]) || ! is_array($byLocale)) {
                continue;
            }

            $kept = [];

            foreach ($locales as $locale) {
                $clean = $this->clean($byLocale[$locale] ?? [], $locale);

                if ($clean !== []) {
                    $kept[$locale] = $clean;
                }
            }

            if ($kept !== []) {
                $out[$word] = $kept;
            }
        }

        return $out;
    }

    /** @return list<string> */
    protected function clean(mixed $values, string $locale): array
    {
        $out = [];
        $wantsCyrillic = in_array($locale, self::CYRILLIC, true);

        foreach ((array) $values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = $this->orthography(trim($value), $locale);

            // A sentence is a definition rather than a translation, and the
            // wrong script never matches what a learner types.
            if ($value === '' || mb_strlen($value) > 60) {
                continue;
            }

            $hasCyrillic = (bool) preg_match('/\p{Cyrillic}/u', $value);

            if ($hasCyrillic !== $wantsCyrillic) {
                continue;
            }

            $out[] = $value;
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /**
     * Uzbek writes oʻ and gʻ with U+02BB and the glottal stop with U+02BC, but
     * a model reaches for the ASCII apostrophe about half the time. Both look
     * almost identical and neither the app nor a learner would ever notice —
     * except that a typed answer is compared character by character, so
     * "goʻsht" and "go'sht" would not match. Asking nicely in the prompt is
     * unreliable; converting afterwards is not.
     */
    protected function orthography(string $value, string $locale): string
    {
        if (! in_array($locale, ['uz', 'kaa'], true)) {
            return $value;
        }

        // Any apostrophe-ish character, after o or g, is the turned comma.
        $value = preg_replace('/([ogOG])[\x{2018}\x{2019}\x{0027}\x{0060}\x{00B4}\x{02BC}]/u', '$1ʻ', $value);

        // Everywhere else it is the modifier apostrophe: "taʼlim", "eʼtibor".
        return preg_replace('/[\x{2018}\x{2019}\x{0027}\x{0060}\x{00B4}]/u', 'ʼ', $value);
    }

    /** Models occasionally fence JSON despite being asked not to. */
    protected function unfence(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        }

        return trim((string) $text);
    }

    protected function shorten(string $value, int $length): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }
}
