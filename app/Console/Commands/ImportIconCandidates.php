<?php

namespace App\Console\Commands;

use App\Models\IconCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Loads the offline matcher's shortlist ({"word": "...", "slugs": [...]}) so the
 * admin review screen can show the most likely icons for each word first.
 *
 *   php artisan icons:candidates storage/app/private/icons/candidates.json
 */
class ImportIconCandidates extends Command
{
    protected $signature = 'icons:candidates {file : JSON list of {word, slugs}}';

    protected $description = 'Import per-word icon suggestions for the review screen';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! File::exists($file)) {
            $this->error("Fayl topilmadi: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode(File::get($file), true);

        if (! is_array($rows)) {
            $this->error('JSON roʼyxat kutilgan edi.');

            return self::FAILURE;
        }

        $now = now();
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            IconCandidate::upsert(
                array_map(fn ($row) => [
                    'normalized' => mb_strtolower(trim($row['word'])),
                    'slugs' => json_encode(array_values($row['slugs'] ?? [])),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk),
                ['normalized'],
                ['slugs', 'updated_at'],
            );
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ Takliflar yuklandi: '.count($rows).' · jadvalda: '.IconCandidate::count());

        return self::SUCCESS;
    }
}
