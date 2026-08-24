<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Console\Command;

class CleanTranslations extends Command
{
    protected $signature = 'dictionary:clean {--dry-run : Only report what would change}';

    protected $description = 'Remove machine-translation junk from stored translations';

    public function handle(DictionaryService $dictionary): int
    {
        $dry = $this->option('dry-run');
        $cleaned = $emptied = 0;
        $examples = [];

        Word::whereNotNull('translations')->chunkById(200, function ($words) use ($dictionary, $dry, &$cleaned, &$emptied, &$examples) {
            foreach ($words as $word) {
                $before = $word->translations ?? [];
                $after = $dictionary->rejectEnglish($before);

                if ($after === $before) {
                    continue;
                }

                $removed = collect($before)->flatten()->diff(collect($after)->flatten());

                if (count($examples) < 10) {
                    $examples[] = [$word->word, $removed->implode(', ')];
                }

                $cleaned++;

                if (! isset($after['uz'])) {
                    $emptied++;
                }

                if (! $dry) {
                    // Losing the main translation means the word needs a human.
                    $word->update([
                        'translations' => $after,
                        'needs_review' => true,
                    ]);
                }
            }
        });

        if ($examples) {
            $this->table(['Soʼz', 'Olib tashlangan'], $examples);
        }

        $this->info(($dry ? '[quruq yurish] ' : '')."Tozalandi: {$cleaned} ta soʼz");

        if ($emptied) {
            $this->warn("{$emptied} tasining oʼzbekcha tarjimasi butunlay olib tashlandi — panelda toʼldirish kerak.");
        }

        return self::SUCCESS;
    }
}
