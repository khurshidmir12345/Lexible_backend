<?php

namespace App\Services\Dictionary;

use App\Models\Word;
use App\Services\Dictionary\Providers\FreeDictionaryProvider;
use App\Services\Dictionary\Providers\MyMemoryTranslator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single entry point for "what does this English word mean?".
 *
 *   1. look the word up in our own `words` table
 *   2. hit  -> return it, no network call
 *   3. miss -> ask the free dictionary + translation APIs
 *   4. store the result
 *   5. return it — every later request for the same word is step 2
 */
class DictionaryService
{
    public function __construct(
        protected FreeDictionaryProvider $definitions,
        protected MyMemoryTranslator $translator,
    ) {}

    public function find(string $word): ?Word
    {
        return Word::where('normalized', $this->normalize($word))->first();
    }

    /** Step 1–5 above. Returns null only when the word is not a dictionary word. */
    public function lookup(string $word, bool $enrich = true): ?Word
    {
        if ($existing = $this->find($word)) {
            return $existing;
        }

        if (! $enrich || ! config('dictionary.enrich_on_miss')) {
            return null;
        }

        return $this->import($word);
    }

    /** Fetch a word we have never seen and persist it. */
    public function import(string $word, ?int $frequencyRank = null): ?Word
    {
        $normalized = $this->normalize($word);

        $english = $this->definitions->fetch($normalized);
        if (! $english) {
            return null;
        }

        // Grammar words make poor flashcards — a learner needs "beautiful",
        // not "of". The API is the only place we can tell them apart reliably.
        $keep = config('dictionary.seed.keep_pos');
        if ($keep && ! in_array($english['part_of_speech'], $keep, true)) {
            return null;
        }

        $record = Word::updateOrCreate(
            ['normalized' => $normalized],
            [
                'word' => $english['word'],
                'part_of_speech' => $english['part_of_speech'],
                'transcription' => $english['transcription'],
                'audio_url' => $english['audio_url'],
                'definition' => array_filter(['en' => $english['definition']]),
                'example' => array_filter(['en' => $english['example']]),
                'synonyms' => $english['synonyms'],
                'frequency_rank' => $frequencyRank,
                'source' => 'api',
                'needs_review' => true,
            ],
        );

        return $record;
    }

    /**
     * Fill in the translations for a word we already have.
     * Karakalpak is skipped on purpose — no free engine covers it.
     */
    public function translate(Word $word, ?array $locales = null): Word
    {
        $locales ??= config('dictionary.languages.auto');
        $missing = array_values(array_filter(
            $locales,
            fn ($locale) => empty($word->translations[$locale] ?? []),
        ));

        if (! $missing) {
            return $word;
        }

        try {
            $fresh = $this->translator->translate($word->word, $missing);
        } catch (\Throwable $e) {
            Log::warning('Translation failed', ['word' => $word->word, 'error' => $e->getMessage()]);

            return $word;
        }

        if ($fresh) {
            $word->translations = array_merge($word->translations ?? [], $fresh);
            $word->save();
        }

        return $word;
    }

    /** Search used by the "add words" screen. */
    public function search(string $query, string $locale = 'uz', int $limit = 30)
    {
        $query = trim($query);

        if ($query === '') {
            return Word::usable($locale)->orderBy('frequency_rank')->limit($limit)->get();
        }

        $needle = Str::lower($query);

        return Word::usable($locale)
            ->where(fn ($q) => $q
                ->where('normalized', 'like', $needle.'%')
                ->orWhere('translations->'.$locale, 'like', '%'.$needle.'%'))
            ->orderByRaw('CASE WHEN normalized = ? THEN 0 ELSE 1 END', [$needle])
            ->orderBy('frequency_rank')
            ->limit($limit)
            ->get();
    }

    protected function normalize(string $word): string
    {
        return Str::lower(trim($word));
    }
}
