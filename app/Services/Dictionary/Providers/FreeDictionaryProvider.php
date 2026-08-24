<?php

namespace App\Services\Dictionary\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * dictionaryapi.dev — free, keyless English dictionary.
 *
 * Returns the English half of a word entry: how it is pronounced, what part of
 * speech it is, what it means and a sentence using it. The translations come
 * from a separate provider.
 */
class FreeDictionaryProvider
{
    /**
     * @return array{
     *     word: string, part_of_speech: ?string, transcription: ?string,
     *     audio_url: ?string, definition: ?string, example: ?string, synonyms: array
     * }|null  null when the word is not in the dictionary
     */
    public function fetch(string $word): ?array
    {
        $response = Http::timeout(config('dictionary.definitions.timeout'))
            ->acceptJson()
            ->get(config('dictionary.definitions.url').urlencode($word));

        if (! $response->successful()) {
            return null;   // 404 simply means "not a dictionary word"
        }

        $entries = $response->json();
        if (! is_array($entries) || ! isset($entries[0])) {
            return null;
        }

        // Merge every entry: the first one often lacks audio that a later one has.
        $meanings = collect($entries)->flatMap(fn ($e) => $e['meanings'] ?? []);
        $primary = $this->primaryMeaning($meanings->all());
        $definition = $this->bestDefinition($primary);

        return [
            'word' => $entries[0]['word'] ?? $word,
            'part_of_speech' => $primary['partOfSpeech'] ?? null,
            'transcription' => $this->transcription($entries),
            'audio_url' => $this->audio($entries),
            'definition' => $definition['definition'] ?? null,
            'example' => $definition['example'] ?? null,
            'synonyms' => array_values(array_slice(array_unique(array_merge(
                $primary['synonyms'] ?? [],
                $definition['synonyms'] ?? [],
            )), 0, 6)),
        ];
    }

    /**
     * The source is Wiktionary, where the first sense is often archaic or
     * technical ("site: sorrow, grief"). Score each meaning instead of taking
     * the first: senses that carry example sentences and have several
     * definitions are the ones people actually use.
     */
    protected function primaryMeaning(array $meanings): array
    {
        // How many senses a part of speech has is the strongest signal of which
        // one people mean: "beautiful" has one throwaway noun sense but several
        // adjective ones, while "home" is a noun far more often than a verb.
        $posRank = ['noun' => 5, 'adjective' => 4, 'verb' => 4, 'adverb' => 3, 'interjection' => 2];

        $scored = array_map(function (array $meaning) use ($posRank) {
            $definitions = $meaning['definitions'] ?? [];
            $withExample = count(array_filter($definitions, fn ($d) => filled($d['example'] ?? null)));

            return [
                'meaning' => $meaning,
                'score' => min(count($definitions), 5) * 3
                    + min($withExample, 3) * 2
                    + ($posRank[$meaning['partOfSpeech'] ?? ''] ?? 0),
            ];
        }, $meanings);

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['meaning'] ?? [];
    }

    /** Within a meaning, the definition that comes with a sentence is the teachable one. */
    protected function bestDefinition(array $meaning): array
    {
        $definitions = $meaning['definitions'] ?? [];

        foreach ($definitions as $definition) {
            if (filled($definition['example'] ?? null)) {
                return $definition;
            }
        }

        return $definitions[0] ?? [];
    }

    protected function transcription(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (filled($entry['phonetic'] ?? null)) {
                return $entry['phonetic'];
            }
            foreach ($entry['phonetics'] ?? [] as $p) {
                if (filled($p['text'] ?? null)) {
                    return $p['text'];
                }
            }
        }

        return null;
    }

    /** Prefer the US recording, fall back to whatever audio exists. */
    protected function audio(array $entries): ?string
    {
        $candidates = collect($entries)
            ->flatMap(fn ($e) => $e['phonetics'] ?? [])
            ->pluck('audio')
            ->filter()
            ->values();

        return $candidates->first(fn ($url) => Str::contains($url, '-us.'))
            ?? $candidates->first();
    }

}
