<?php

namespace App\Console\Commands;

use App\Models\Icon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Loads the icon library's meta.json into the `icons` table.
 *
 * Expects the rendered WebP files to already sit on the public disk under
 * icons/256 and icons/512 — this command records what exists, it does not
 * resize anything. Safe to re-run: rows are matched by slug.
 *
 *   php artisan icons:import                      # storage/app/private/icons/meta.json
 *   php artisan icons:import --meta=/path/meta.json
 */
class ImportIcons extends Command
{
    protected $signature = 'icons:import
        {--meta= : Path to meta.json (default: private disk icons/meta.json)}';

    protected $description = 'Register the 3D icon library (meta.json) in the icons table';

    public function handle(): int
    {
        $meta = $this->option('meta') ?: storage_path('app/private/icons/meta.json');

        if (! File::exists($meta)) {
            $this->error("meta.json topilmadi: {$meta}");

            return self::FAILURE;
        }

        $items = json_decode(File::get($meta), true)['items'] ?? [];

        if ($items === []) {
            $this->error('meta.json ichida "items" yoʼq.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $now = now();
        $rows = [];
        $missing = [];

        foreach ($items as $item) {
            $slug = $item['slug'] ?? pathinfo($item['file_name'] ?? '', PATHINFO_FILENAME);

            if ($slug === '') {
                continue;
            }

            foreach (Icon::SIZES as $size) {
                if (! $disk->exists(Icon::pathFor($slug, $size))) {
                    $missing[] = Icon::pathFor($slug, $size);
                }
            }

            $rows[] = [
                'slug' => $slug,
                'title' => $item['title'] ?? $slug,
                'category' => $item['category'] ?? '',
                'tags' => json_encode(array_values($item['tags'] ?? [])),
                'volume' => (int) ($item['volume'] ?? 1),
                'path' => Icon::pathFor($slug),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            Icon::upsert($chunk, ['slug'], ['title', 'category', 'tags', 'volume', 'path', 'updated_at']);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ Ikonkalar roʼyxatga olindi: '.count($rows).' · jadvalda: '.Icon::count());

        if ($missing !== []) {
            $this->warn('⚠️  Public diskda topilmagan fayllar: '.count($missing));
            foreach (array_slice($missing, 0, 10) as $path) {
                $this->line('   '.$path);
            }
        }

        return self::SUCCESS;
    }
}
