<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Loads hand-written translation files into the dictionary.
 *
 * The translations live in the repository as plain JSON, one directory per
 * language:
 *
 *   storage/app/dictionary/translations/uz/0001-1000.json
 *   storage/app/dictionary/translations/ru/0001-1000.json
 *
 * Each file is a flat map of English word to accepted answers, best first:
 *
 *   {"home": ["uy", "turar joy"], "water": ["suv"]}
 *
 * Keeping them as files rather than only in the database means they survive a
 * rebuild, can be reviewed in a diff, corrected by hand, and reused when a new
 * language is added — the format does not change, only the directory.
 */
class LoadTranslations extends Command
{
    protected $signature = 'dictionary:load-translations
        {--lang=uz : Language directory to load}
        {--dir= : Override the translations directory}
        {--force : Overwrite translations that are already in place}
        {--dry : Report what would change, write nothing}';

    protected $description = 'Load hand-written translation files into the words table';

    public function handle(): int
    {
        $lang = $this->option('lang');
        $dir = $this->option('dir') ?: storage_path("app/dictionary/translations/{$lang}");

        if (! File::isDirectory($dir)) {
            $this->error("Papka topilmadi: {$dir}");

            return self::FAILURE;
        }

        $files = collect(File::files($dir))
            ->filter(fn ($f) => $f->getExtension() === 'json')
            ->sortBy(fn ($f) => $f->getFilename());

        if ($files->isEmpty()) {
            $this->warn("{$dir} ichida json fayl yoʼq.");

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $force = (bool) $this->option('force');

        $applied = $missing = $skipped = $bad = 0;
        $unknown = [];
        $rejected = [];

        foreach ($files as $file) {
            $entries = json_decode(File::get($file->getPathname()), true);

            if (! is_array($entries)) {
                $this->error("  {$file->getFilename()}: JSON oʼqib boʼlmadi");
                $bad++;

                continue;
            }

            // One query per file rather than one per word.
            $words = Word::whereIn('normalized', array_map('strtolower', array_keys($entries)))
                ->get()
                ->keyBy('normalized');

            $fileApplied = 0;

            foreach ($entries as $english => $translations) {
                $key = strtolower(trim($english));
                $word = $words->get($key);

                if (! $word) {
                    $missing++;
                    if (count($unknown) < 12) {
                        $unknown[] = $english;
                    }

                    continue;
                }

                $clean = $this->clean($translations);

                // A silently dropped translation is worse than a loud one:
                // Cyrillic look-alikes ("bekа" with a Cyrillic а) are almost
                // impossible to spot by eye but never match a typed answer.
                if (count($clean) !== count((array) $translations)) {
                    $rejected[$english] = array_values(array_diff((array) $translations, $clean));
                }

                if ($clean === []) {
                    $bad++;

                    continue;
                }

                $existing = $word->translations ?? [];

                // A translation already written by hand is not replaced unless
                // asked, so a re-run is always safe.
                if (! $force && ($existing[$lang] ?? []) !== [] && $word->translation_source === 'manual') {
                    $skipped++;

                    continue;
                }

                if (! $dry) {
                    $existing[$lang] = $clean;

                    $word->forceFill([
                        'translations' => $existing,
                        'translation_status' => 'done',
                        'translation_source' => 'manual',
                        'translated_at' => now(),
                        'needs_review' => false,
                    ])->saveQuietly();
                }

                $applied++;
                $fileApplied++;
            }

            $this->line(sprintf('  %-24s %s ta', $file->getFilename(), number_format($fileApplied)));
        }

        $this->newLine();
        $this->info(sprintf('%s ta tarjima %s',
            number_format($applied),
            $dry ? 'yoziladi (quruq ishga tushirish)' : 'yozildi',
        ));

        if ($skipped) {
            $this->line('  qoʼlda yozilgani saqlab qolindi: '.number_format($skipped).' (--force bilan almashtiriladi)');
        }

        if ($missing) {
            $this->line('  bazada topilmadi: '.number_format($missing).' — '.implode(', ', $unknown));
        }

        if ($rejected) {
            $this->newLine();
            $this->warn('Rad etilgan variantlar (kirill harfi yoki juda uzun):');

            foreach (array_slice($rejected, 0, 15, true) as $english => $values) {
                $this->line("  {$english}: ".implode(', ', array_map(
                    fn ($v) => '"'.$v.'"', $values,
                )));
            }
        }

        if ($bad) {
            $this->line('  butunlay yaroqsiz yozuv: '.number_format($bad));
        }

        $left = Word::where('is_teachable', true)
            ->whereNotNull('frequency_rank')
            ->whereNull('translations->'.$lang)
            ->count();

        $this->line("  tarjimasiz qolgan (oʼrgatiladigan, chastotali): ".number_format($left));

        return self::SUCCESS;
    }

    /** @return list<string> */
    protected function clean(mixed $translations): array
    {
        $out = [];

        foreach ((array) $translations as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            // Cyrillic would never match a typed answer, and a sentence is a
            // definition rather than a translation.
            if ($value === '' || preg_match('/\p{Cyrillic}/u', $value) || mb_strlen($value) > 60) {
                continue;
            }

            $out[] = $value;
        }

        return array_slice(array_values(array_unique($out)), 0, 4);
    }
}
