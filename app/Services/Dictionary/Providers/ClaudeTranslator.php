<?php

namespace App\Services\Dictionary\Providers;

use Anthropic\Client;
use RuntimeException;

/**
 * Translates English words into Uzbek with Claude.
 *
 * The reason this exists rather than a plain translation API: a bare word has
 * no meaning to translate. "home" sent on its own comes back "bosh sahifa"
 * because translation memories are full of software strings, and "light" is a
 * coin flip between "yorugʻlik" and "yengil". Sending the part of speech, the
 * English definition and an example sentence removes the ambiguity, and that
 * is something a translation endpoint has no field for.
 *
 * Words are sent in groups because the instructions are far longer than the
 * words themselves — one word per request would spend most of the budget
 * repeating the prompt.
 */
class ClaudeTranslator
{
    public function __construct(protected ?Client $client = null) {}

    /**
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     * @return array<string, list<string>> word => Uzbek forms, best first
     */
    public function translate(array $words): array
    {
        if ($words === []) {
            return [];
        }

        return $this->parse($this->send($words), $words);
    }

    /**
     * The one place that talks to the network, kept apart so the prompt and
     * the answer-checking either side of it can be tested without it.
     *
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     */
    protected function send(array $words): string
    {
        $params = $this->batchParams($words);

        $response = $this->client()->messages->create(
            model: $params['model'],
            maxTokens: $params['maxTokens'],
            // The instructions are identical on every request; caching them is
            // most of the saving on a run this size.
            system: $params['system'],
            messages: $params['messages'],
        );

        $text = '';

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /**
     * The request body for one group of words, shared by the live call and
     * the Batches API so both send exactly the same thing.
     *
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     */
    public function batchParams(array $words): array
    {
        return [
            'model' => config('dictionary.claude.model'),
            'maxTokens' => config('dictionary.claude.max_tokens'),
            'system' => [[
                'type' => 'text',
                'text' => $this->instructions(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => [[
                'role' => 'user',
                'content' => $this->payload($words),
            ]],
        ];
    }

    /** The same prompt on every call, so it stays cacheable byte for byte. */
    protected function instructions(): string
    {
        return <<<'TXT'
        You translate English vocabulary into Uzbek for a language-learning app
        used by schoolchildren in Uzbekistan.

        For each entry you are given the English word, its part of speech, its
        English definition and sometimes an example sentence. Translate the
        word **as used in that definition** — not as it might appear in a
        software menu, a brand name or a different part of speech.

        Rules:
        - Write Uzbek in the Latin alphabet, using the modern orthography:
          oʻ and gʻ with the ʻ mark (not o' or g'), and the apostrophe ʼ where
          one belongs. Never Cyrillic.
        - Give the plain dictionary form: a noun in the singular nominative, a
          verb in the -moq infinitive, an adjective in its base form.
        - Give 1 to 3 translations, best first. Add a second only when it is
          genuinely used for the same sense — do not pad with near-synonyms.
        - Translate the word, not the definition. "dwelling" for `home` is
          right; "yashash joyi haqidagi tushuncha" is not.
        - No explanations, no Latin transliteration of the English, no notes
          in brackets.
        - If a word has no real Uzbek equivalent (a proper noun, a purely
          technical term, an English word Uzbek simply borrows), give the form
          Uzbek speakers actually use — usually the borrowed word spelled in
          Uzbek orthography.
        - If you genuinely cannot translate an entry, give an empty array for
          it. Never invent a word.

        Reply with JSON only — an object mapping each English word to an array
        of Uzbek translations. No prose before or after, no markdown fence.

        Example reply:
        {"home":["uy","turar joy"],"light":["yengil"],"run":["yugurmoq"]}
        TXT;
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
                $line .= ' — '.$this->trim($entry['gloss'], 220);
            }

            if (! empty($entry['example'])) {
                $line .= ' | e.g. '.$this->trim($entry['example'], 120);
            }

            $lines[] = $line;
        }

        return "Translate these ".count($words)." entries:\n\n".implode("\n", $lines);
    }

    /**
     * @param  list<array{word: string, pos: ?string, gloss: ?string, example: ?string}>  $words
     * @return array<string, list<string>>
     */
    public function parse(string $text, array $words): array
    {
        $decoded = json_decode($this->unfence($text), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Claude javobini oʼqib boʼlmadi: '.mb_substr($text, 0, 200));
        }

        // Only accept keys we asked about — a hallucinated extra word must not
        // reach the dictionary.
        $asked = array_flip(array_column($words, 'word'));
        $out = [];

        foreach ($decoded as $word => $translations) {
            if (! isset($asked[$word]) || ! is_array($translations)) {
                continue;
            }

            $clean = $this->clean($translations);

            if ($clean !== []) {
                $out[$word] = $clean;
            }
        }

        return $out;
    }

    /** @return list<string> */
    protected function clean(array $translations): array
    {
        $out = [];

        foreach ($translations as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Cyrillic would break typed answers, and a sentence is a
            // definition rather than a translation.
            if ($value === '' || preg_match('/\p{Cyrillic}/u', $value) || mb_strlen($value) > 60) {
                continue;
            }

            $out[] = $value;
        }

        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /** Models occasionally wrap JSON in a code fence despite being asked not to. */
    protected function unfence(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        }

        return trim((string) $text);
    }

    protected function trim(string $value, int $length): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }

    protected function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $key = config('dictionary.claude.key');

        if (! $key) {
            throw new RuntimeException(
                'ANTHROPIC_API_KEY oʼrnatilmagan — .env fayliga qoʼshing.',
            );
        }

        return $this->client = new Client(apiKey: $key);
    }
}
