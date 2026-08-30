<?php

namespace App\Console\Commands;

use App\Services\Dictionary\EmojiMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads the compact file produced by `dictionary:distill` into `words`.
 *
 * Two things happen here that the distiller cannot do, because they need
 * judgement about the app rather than about Wiktionary:
 *
 *   - **Ranking.** The dump has no idea which words matter. A frequency list
 *     supplies that, and it is what decides the order stages are filled in.
 *     A word outside the list is still stored and still searchable — it just
 *     never gets handed to a learner unasked.
 *   - **Keeping.** Wiktionary's long tail (`abaciscus`, `abactor`) has no
 *     definition and no frequency. Those are dropped; a word is kept when it
 *     either means something we can show or is common enough to be looked up.
 */
class ImportDictionary extends Command
{
    protected $signature = 'dictionary:import
        {--file=storage/app/dictionary/words.jsonl : Compact file from dictionary:distill}
        {--frequency=database/dictionary/frequency-en.txt : Frequency-ordered word list}
        {--chunk=2000 : Rows per insert}
        {--fresh : Wipe machine-imported words first}
        {--dry : Count what would happen, write nothing}';

    protected $description = 'Load the distilled dictionary into the words table';

    /**
     * Frequency bands mapped onto the CEFR levels the onboarding asks about,
     * so a beginner is taught the first thousand words and not the last.
     */
    protected const LEVELS = [
        1000 => 'A1', 2500 => 'A2', 5000 => 'B1', 10000 => 'B2', 20000 => 'C1',
    ];

    public function handle(EmojiMatcher $emojis): int
    {
        $file = $this->option('file');
        $dry = (bool) $this->option('dry');

        if (! is_readable($file)) {
            $this->error("Faylni oʼqib boʼlmadi: {$file}. Avval `dictionary:distill` ni ishlating.");

            return self::FAILURE;
        }

        $rank = $this->frequency();
        $this->info(number_format(count($rank)).' ta soʼz chastota roʼyxatida');

        if ($this->option('fresh') && ! $dry) {
            $this->warn('Mashina yozgan soʼzlar oʼchirilmoqda…');
            DB::table('words')->whereIn('source', ['import', 'api'])->delete();
        }

        $handle = fopen($file, 'r');
        $now = now();
        $buffer = [];
        $read = $kept = $skipped = $withEmoji = $fromWiktionary = 0;

        while (($line = fgets($handle)) !== false) {
            $read++;
            $entry = json_decode($line, true);

            if (! is_array($entry) || empty($entry['w'])) {
                continue;
            }

            $word = $entry['w'];
            $position = $rank[$word] ?? null;

            // No definition and nobody uses it — nothing to teach and nothing
            // to look up.
            if (empty($entry['g']) && $position === null) {
                $skipped++;
                continue;
            }

            $uz = array_values(array_filter($entry['uz'] ?? []));
            $emoji = $emojis->match($word, $entry['p'] ?? null);

            if ($emoji) {
                $withEmoji++;
            }
            if ($uz) {
                $fromWiktionary++;
            }

            $buffer[] = [
                'word' => $word,
                'normalized' => $word,
                'part_of_speech' => $entry['p'] ?? null,
                'pos_all' => json_encode($entry['pos'] ?? [$entry['p'] ?? null]),
                'transcription' => $entry['ipa'] ?? null,
                'translations' => $uz ? json_encode(['uz' => $uz], JSON_UNESCAPED_UNICODE) : null,
                'definition' => ! empty($entry['g'])
                    ? json_encode(['en' => $entry['g']], JSON_UNESCAPED_UNICODE)
                    : null,
                'example' => ! empty($entry['ex'])
                    ? json_encode(['en' => $entry['ex']], JSON_UNESCAPED_UNICODE)
                    : null,
                'synonyms' => ! empty($entry['syn'])
                    ? json_encode($entry['syn'], JSON_UNESCAPED_UNICODE)
                    : null,
                'emoji' => $emoji,
                'cefr_level' => $this->level($position),
                'frequency_rank' => $position,
                'source' => 'import',
                'is_teachable' => $this->teachable($word, $entry['p'] ?? null, $entry['g'] ?? null),
                // Wiktionary's own Uzbek is human-written, so it is finished.
                'translation_status' => $uz ? 'done' : 'pending',
                'translation_source' => $uz ? 'wiktionary' : null,
                'translated_at' => $uz ? $now : null,
                'needs_review' => ! $uz,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $kept++;

            if (count($buffer) >= (int) $this->option('chunk')) {
                $this->flush($buffer, $dry);
                $buffer = [];
                $this->line(sprintf('  %s oʼqildi · %s yozildi · %s tashlandi',
                    number_format($read), number_format($kept), number_format($skipped)));
            }
        }

        $this->flush($buffer, $dry);
        fclose($handle);

        $this->newLine();
        $this->info(sprintf('%s oʼqildi → %s soʼz%s', number_format($read), number_format($kept),
            $dry ? ' (quruq ishga tushirish — hech narsa yozilmadi)' : ' bazaga yozildi'));
        $this->line('  chastota roʼyxatidan tashqarida boʼlgani uchun tashlandi: '.number_format($skipped));
        $this->line('  emojisi bor: '.number_format($withEmoji));
        $this->line('  Wiktionary oʼzbekchasi bilan keldi: '.number_format($fromWiktionary));
        $this->line('  tarjima kutmoqda: '.number_format($kept - $fromWiktionary));

        return self::SUCCESS;
    }

    /**
     * Writes a chunk. `upsert` rather than `insert` so the command can be
     * re-run after a crash, and so a word a teacher typed by hand keeps its
     * own translation.
     */
    protected function flush(array $rows, bool $dry): void
    {
        if ($rows === [] || $dry) {
            return;
        }

        DB::table('words')->upsert(
            $rows,
            ['normalized'],
            [
                'part_of_speech', 'pos_all', 'transcription', 'definition',
                'example', 'synonyms', 'emoji', 'cefr_level', 'frequency_rank',
                'is_teachable', 'updated_at',
            ],
        );
    }

    /**
     * Whether the word is worth putting on a flashcard.
     *
     * "the" is the third most common word in English and teaches a learner
     * nothing — its dictionary entry reads "with a comparative, establishes a
     * correlation". Grammar words stay searchable but are never dealt out.
     */
    protected function teachable(string $word, ?string $pos, ?string $gloss): bool
    {
        static $skip = null;
        $skip ??= array_flip(config('dictionary.seed.skip_words', []));

        if (isset($skip[$word]) || mb_strlen($word) <= 2) {
            return false;
        }

        if (! in_array($pos, config('dictionary.seed.keep_pos', []), true)) {
            return false;
        }

        // Without a definition there is nothing to translate *from*, and the
        // words in that state are the ones worth skipping anyway: inflected
        // forms ("went", "made"), stage directions ("sighs", "groans"),
        // letters and abbreviations. They stay searchable — a teacher typing
        // "went" still finds it — but they are never dealt out, and a human
        // writing a translation by hand puts one back (see
        // dictionary:load-translations).
        if (blank($gloss)) {
            return false;
        }

        // "em", "ya", "de" — the entry is about the letter, not a word.
        return ! preg_match('/^the name of the .*(letter|script)/i', $gloss);
    }

    /** word => 1-based position in the frequency list. */
    protected function frequency(): array
    {
        $path = $this->option('frequency');

        if (! is_readable($path)) {
            $this->warn("Chastota roʼyxati topilmadi: {$path} — barcha soʼzlar darajasiz qoladi.");

            return [];
        }

        $rank = [];
        $handle = fopen($path, 'r');
        $position = 0;

        while (($line = fgets($handle)) !== false) {
            $word = strtolower(trim(strtok($line, " \t")));

            if ($word !== '' && ! isset($rank[$word])) {
                $rank[$word] = ++$position;
            }
        }

        fclose($handle);

        return $rank;
    }

    protected function level(?int $position): ?string
    {
        if ($position === null) {
            return null;
        }

        foreach (self::LEVELS as $ceiling => $level) {
            if ($position <= $ceiling) {
                return $level;
            }
        }

        return null;
    }
}
