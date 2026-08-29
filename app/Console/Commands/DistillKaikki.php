<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Boils the 3 GB Kaikki Wiktionary dump down to the handful of fields the app
 * actually uses, one line per word.
 *
 * The dump is far too big to ship to the server or to load into a database
 * directly: it carries etymology trees, descendants, every inflected form and
 * translations into 500 languages. What a vocabulary app needs is the word,
 * its part of speech, how it sounds, what it means, one example sentence, and
 * whatever Uzbek Wiktionary already has.
 *
 * Entries for the same word are contiguous in the dump, so the merge runs in
 * constant memory no matter how large the file is.
 */
class DistillKaikki extends Command
{
    protected $signature = 'dictionary:distill
        {source : Path to the kaikki English .jsonl dump}
        {--out=storage/app/dictionary/words.jsonl : Where to write the compact file}
        {--report=25000 : Print progress every N lines}';

    protected $description = 'Reduce the Kaikki Wiktionary dump to one compact line per word';

    /** Parts of speech worth teaching. Everything else is grammar plumbing. */
    protected const POS = [
        'noun' => 'noun', 'verb' => 'verb', 'adj' => 'adjective', 'adv' => 'adverb',
        'num' => 'numeral', 'pron' => 'pronoun', 'prep' => 'preposition',
        'conj' => 'conjunction', 'intj' => 'interjection', 'det' => 'determiner',
    ];

    /** The order we prefer when a word is several parts of speech at once. */
    protected const POS_RANK = ['noun', 'verb', 'adj', 'adv', 'intj', 'num', 'pron', 'prep', 'conj', 'det'];

    /**
     * Senses carrying any of these are not what a learner should be taught,
     * even when the word itself is common.
     */
    protected const SKIP_SENSE = [
        'obsolete', 'archaic', 'rare', 'dialectal', 'nonstandard', 'misspelling',
        'abbreviation', 'initialism', 'acronym', 'taxonomic', 'offensive',
        'vulgar', 'slur', 'ethnic-slur', 'religious-slur', 'derogatory',
    ];

    public function handle(): int
    {
        $source = $this->argument('source');

        if (! is_readable($source)) {
            $this->error("Faylni oʼqib boʼlmadi: {$source}");

            return self::FAILURE;
        }

        $out = $this->option('out');
        @mkdir(dirname($out), 0755, true);

        $in = fopen($source, 'r');
        $dest = fopen($out, 'w');
        $report = max((int) $this->option('report'), 1000);

        $lines = $kept = 0;
        $current = null;          // the word being accumulated
        $started = microtime(true);

        while (($line = fgets($in)) !== false) {
            $lines++;

            if ($lines % $report === 0) {
                $rate = (int) ($lines / max(microtime(true) - $started, 0.001));
                $this->line(sprintf('  %s qator · %s soʼz · %s qator/s',
                    number_format($lines), number_format($kept), number_format($rate)));
            }

            $entry = json_decode($line, true);

            if (! is_array($entry) || ($entry['lang_code'] ?? null) !== 'en') {
                continue;
            }

            $word = trim((string) ($entry['word'] ?? ''));

            // A new word means the previous one is complete.
            if ($current && $current['w'] !== $word) {
                $kept += $this->flush($dest, $current);
                $current = null;
            }

            $shaped = $this->shape($entry);

            if (! $shaped) {
                continue;
            }

            $current = $current ? $this->merge($current, $shaped) : $shaped;
        }

        if ($current) {
            $kept += $this->flush($dest, $current);
        }

        fclose($in);
        fclose($dest);

        $this->newLine();
        $this->info(sprintf(
            '✅ %s qator oʼqildi → %s soʼz yozildi (%s)',
            number_format($lines), number_format($kept), $this->size($out),
        ));

        return self::SUCCESS;
    }

    /** One dump entry, reduced — or null when it is not worth keeping. */
    protected function shape(array $entry): ?array
    {
        $word = trim((string) ($entry['word'] ?? ''));
        $pos = self::POS[$entry['pos'] ?? ''] ?? null;

        if ($word === '' || ! $pos) {
            return null;
        }

        // Single words only: a flashcard for "put up with" is a different
        // exercise, and proper nouns are not vocabulary.
        if (str_contains($word, ' ') || mb_strtoupper(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1)) {
            return null;
        }

        if (! preg_match("/^[a-z][a-z'\-]*$/", $word)) {
            return null;
        }

        $senses = $this->senses($entry);
        $raw = count($entry['senses'] ?? []);

        // Wiktionary sometimes glosses an entire entry with a topic template —
        // every sense of the noun "cat" reads "Terms relating to animals." The
        // word is still worth keeping; it just arrives without a definition.
        if ($raw === 0) {
            return null;
        }

        return [
            'w' => $word,
            'p' => $pos,
            'pos' => [$pos],
            // How often the word is used *as this part of speech*. "free" is a
            // noun in football and an adjective everywhere else, and the size
            // of each entry is what tells them apart.
            'n' => $raw,
            'ipa' => $this->ipa($entry),
            'g' => $senses[0]['gloss'] ?? null,
            'ex' => $this->pickExample($senses),
            'syn' => $this->synonyms($entry),
            'uz' => $this->uzbek($entry),
        ];
    }

    /**
     * Later entries for the same word add their part of speech and fill in
     * anything the first one was missing.
     */
    protected function merge(array $a, array $b): array
    {
        $a['pos'] = array_values(array_unique(array_merge($a['pos'], $b['pos'])));
        $a['ipa'] ??= $b['ipa'];
        $a['ex'] ??= $b['ex'];
        $a['syn'] = array_slice(array_values(array_unique(array_merge($a['syn'], $b['syn']))), 0, 6);
        $a['uz'] = array_values(array_unique(array_merge($a['uz'], $b['uz'])));

        // The reading with more senses is the one the word is mostly used
        // for; the fixed order only breaks ties.
        $better = $b['n'] > $a['n']
            || ($b['n'] === $a['n'] && $this->rank($b['p']) < $this->rank($a['p']));

        if ($better) {
            $a['p'] = $b['p'];
            $a['n'] = $b['n'];
            $a['g'] = $b['g'] ?? $a['g'];
            $a['ex'] = $b['ex'] ?? $a['ex'];
        }

        // A definition is never borrowed across parts of speech: glossing the
        // noun "cat" with "to hoist an anchor" is worse than no gloss at all.
        // Words that end up bare are translated from the word and its part of
        // speech alone.
        if ($a['p'] !== $b['p']) {
            return $a;
        }

        // Wiktionary splits a word by etymology, so "cat" is one noun entry
        // for the animal and another for the Unix command. A one-sense entry
        // must not supply the definition for an eighteen-sense one, or the
        // card teaches the wrong meaning entirely.
        if ($b['n'] >= $a['n']) {
            $a['g'] ??= $b['g'];
        }

        $a['ex'] ??= $b['ex'];

        return $a;
    }

    protected function rank(string $pos): int
    {
        $short = array_search($pos, self::POS, true) ?: $pos;
        $index = array_search($short, self::POS_RANK, true);

        return $index === false ? 99 : $index;
    }

    protected function flush($dest, array $word): int
    {
        unset($word['n']);

        // Empty strings and empty arrays are noise in a file this size.
        $word = array_filter($word, fn ($v) => $v !== null && $v !== [] && $v !== '');

        fwrite($dest, json_encode($word, JSON_UNESCAPED_UNICODE)."\n");

        return 1;
    }

    /** @return list<array{gloss: string, example: ?string}> */
    protected function senses(array $entry): array
    {
        $out = [];

        foreach ($entry['senses'] ?? [] as $sense) {
            $gloss = $sense['glosses'][0] ?? null;

            if (! $gloss || isset($sense['form_of']) || isset($sense['alt_of'])) {
                continue;
            }

            $tags = array_map('strtolower', $sense['tags'] ?? []);

            if (array_intersect($tags, self::SKIP_SENSE)) {
                continue;
            }

            // Wiktionary writes redirects as prose without tagging them:
            // "Commonwealth standard spelling of encyclopedia." Teaching that
            // as a definition is worse than having no entry at all.
            if (preg_match('/\b(spelling|form|plural|singular|tense|participle|comparative|superlative|abbreviation|initialism|acronym) of\b/i', $gloss)) {
                continue;
            }

            // Some entries carry a topic label where a definition belongs —
            // "cat" is glossed "Terms relating to animals." Showing that on a
            // flashcard teaches nothing.
            if (preg_match('/^(terms?|words?)\s+(relating|pertaining|used)\b/i', $gloss)) {
                continue;
            }

            [$example, $quote] = $this->example($sense);

            $out[] = [
                'gloss' => mb_substr(trim($gloss), 0, 400),
                'example' => $example,
                'quote' => $quote,
            ];
        }

        return $out;
    }

    /**
     * Wiktionary mixes made-up usage examples with quotations from old books.
     * Only the first kind reads as modern English, so it is preferred and a
     * quotation is used only when nothing else exists.
     */
    /** @return array{0: ?string, 1: ?string} usage example, then a quotation */
    protected function example(array $sense): array
    {
        $example = $quote = null;

        foreach ($sense['examples'] ?? [] as $candidate) {
            $text = trim((string) ($candidate['text'] ?? ''));

            if ($text === '' || mb_strlen($text) > 120 || mb_strlen($text) < 10) {
                continue;
            }

            // Long-s, slashed line breaks and "[sic]" mark scanned 17th
            // century prose. It is real English, but not the kind to learn from.
            if (preg_match('/[ſ]|\/ | \[sic\]|\^\(/u', $text)) {
                continue;
            }

            if (($candidate['type'] ?? null) === 'example') {
                $example ??= $text;
            } else {
                $quote ??= $text;
            }
        }

        return [$example, $quote];
    }

    /**
     * A made-up usage example from any sense beats a book quotation from the
     * first one, so the whole reading is searched before settling.
     */
    protected function pickExample(array $senses): ?string
    {
        foreach ($senses as $sense) {
            if ($sense['example']) {
                return $sense['example'];
            }
        }

        foreach ($senses as $sense) {
            if ($sense['quote']) {
                return $sense['quote'];
            }
        }

        return null;
    }

    protected function ipa(array $entry): ?string
    {
        foreach ($entry['sounds'] ?? [] as $sound) {
            if (! empty($sound['ipa'])) {
                return mb_substr($sound['ipa'], 0, 120);
            }
        }

        return null;
    }

    /** @return list<string> */
    protected function synonyms(array $entry): array
    {
        $out = [];

        foreach ($entry['synonyms'] ?? [] as $row) {
            $value = trim((string) ($row['word'] ?? ''));

            if ($value !== '' && ! str_contains($value, ' ')) {
                $out[] = $value;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    /**
     * Whatever Uzbek Wiktionary already has. Only ~0.2% of words carry one,
     * but a human translation always beats a machine one, so it is taken.
     * Cyrillic spellings are dropped — the app writes Uzbek in Latin.
     *
     * @return list<string>
     */
    protected function uzbek(array $entry): array
    {
        $out = [];

        foreach ($entry['translations'] ?? [] as $row) {
            if (($row['code'] ?? null) !== 'uz') {
                continue;
            }

            if (in_array('Cyrillic', $row['tags'] ?? [], true)) {
                continue;
            }

            $value = trim((string) ($row['word'] ?? ''));

            if ($value !== '' && ! preg_match('/\p{Cyrillic}/u', $value)) {
                $out[] = $value;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 4);
    }

    protected function size(string $path): string
    {
        $bytes = filesize($path) ?: 0;

        return $bytes > 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
