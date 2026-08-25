<?php

namespace App\Console\Commands;

use App\Models\Word;
use Illuminate\Console\Command;

class PurgeCyrillicUzbek extends Command
{
    protected $signature = 'dictionary:purge-cyrillic {--dry-run}';

    protected $description = 'Remove Cyrillic text stored as an Uzbek or Karakalpak translation';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $fixed = $emptied = 0;
        $examples = [];

        Word::whereNotNull('translations')->chunkById(200, function ($words) use ($dry, &$fixed, &$emptied, &$examples) {
            foreach ($words as $word) {
                $translations = $word->translations ?? [];
                $changed = false;

                foreach (['uz', 'kaa'] as $locale) {
                    $values = $translations[$locale] ?? [];
                    $values = is_array($values) ? $values : [$values];

                    $kept = array_values(array_filter(
                        $values,
                        fn ($v) => ! preg_match('/\p{Cyrillic}/u', (string) $v),
                    ));

                    if (count($kept) === count($values)) {
                        continue;
                    }

                    $changed = true;

                    if (count($examples) < 12) {
                        $examples[] = [$word->word, $locale, implode(', ', array_diff($values, $kept))];
                    }

                    if ($kept) {
                        $translations[$locale] = $kept;
                    } else {
                        unset($translations[$locale]);
                        $emptied++;
                    }
                }

                if (! $changed) {
                    continue;
                }

                $fixed++;

                if (! $dry) {
                    // Losing the only translation means a human has to supply one.
                    $word->update(['translations' => $translations, 'needs_review' => true]);
                }
            }
        });

        if ($examples) {
            $this->table(['Soʼz', 'Til', 'Olib tashlangan'], $examples);
        }

        $this->info(($dry ? '[quruq yurish] ' : '')."Tuzatildi: {$fixed} ta soʼz");

        if ($emptied) {
            $this->warn("{$emptied} tasining tarjimasi butunlay olib tashlandi — panelda toʼldirish kerak.");
        }

        return self::SUCCESS;
    }
}
