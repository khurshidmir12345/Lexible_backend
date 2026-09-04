<?php

namespace App\Console\Commands;

use App\Models\Icon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Applies a word → icon mapping produced offline.
 *
 * Matching twenty thousand words against ten thousand icons is an embedding
 * search plus a model choosing among the candidates — a job for a script with
 * numpy, not a request cycle. The script writes a JSON list of
 *   {"word": "chair", "slug": "chair", "confidence": 95, "source": "exact"|"llm"}
 * and this command is the one place that turns it into database rows, so the
 * same file gives the same result locally and on the server.
 *
 * Rows are matched on `words.normalized`, never on the id: the local SQLite
 * and the server MySQL were seeded separately and number the same words
 * differently, so an id-keyed file applied on the server put the wrong
 * picture on almost every word. `word_id` is still accepted for old files,
 * but only when the row carries no `word`.
 *
 *   php artisan icons:assign storage/app/private/icons/mapping.json
 *   php artisan icons:assign mapping.json --min=70      # skip weak matches
 *   php artisan icons:assign mapping.json --force       # also replace hand-picked icons
 */
class AssignIcons extends Command
{
    protected $signature = 'icons:assign
        {file : JSON mapping of word → slug}
        {--min=60 : Ignore matches below this confidence (0-100)}
        {--force : Overwrite icons an admin set by hand}';

    protected $description = 'Attach library icons to words from a mapping file';

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

        $icons = Icon::query()->pluck('id', 'slug');
        $min = (int) $this->option('min');
        $force = (bool) $this->option('force');

        $stats = ['assigned' => 0, 'weak' => 0, 'unknown' => 0, 'kept' => 0, 'cleared' => 0, 'missing' => 0];

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach (array_chunk($rows, 500) as $chunk) {
            // Ids are looked up per chunk: the dictionary holds 400k rows, and
            // pulling every id/word pair at once blows the CLI memory limit.
            $ids = DB::table('words')
                ->whereIn('normalized', collect($chunk)->pluck('word')->filter()->map(fn ($w) => mb_strtolower(trim($w))))
                ->pluck('id', 'normalized');

            DB::transaction(function () use ($chunk, $icons, $ids, $min, $force, &$stats, $bar) {
                foreach ($chunk as $row) {
                    $bar->advance();
                    $wordId = isset($row['word'])
                        ? (int) ($ids[mb_strtolower(trim($row['word']))] ?? 0)
                        : (int) ($row['word_id'] ?? 0);
                    $slug = $row['slug'] ?? null;
                    $confidence = (int) ($row['confidence'] ?? 0);

                    if ($wordId === 0) {
                        $stats['missing']++;

                        continue;
                    }

                    $query = DB::table('words')->where('id', $wordId)
                        ->when(! $force, fn ($q) => $q->where(fn ($q) => $q
                            ->whereNull('icon_source')->orWhere('icon_source', '!=', 'manual')));

                    // A null slug means the matcher found nothing suitable —
                    // drop a stale machine-made pick, keep a hand-made one.
                    if ($slug === null || $confidence < $min) {
                        $affected = $query->whereNotNull('icon_id')->update([
                            'icon_id' => null, 'icon_path' => null,
                            'icon_source' => null, 'icon_confidence' => null,
                        ]);
                        $stats[$slug === null ? 'cleared' : 'weak'] += $affected;

                        continue;
                    }

                    if (! isset($icons[$slug])) {
                        $stats['unknown']++;

                        continue;
                    }

                    $affected = $query->update([
                        'icon_id' => $icons[$slug],
                        'icon_path' => Icon::pathFor($slug),
                        'icon_source' => $row['source'] ?? 'llm',
                        'icon_confidence' => min(100, max(0, $confidence)),
                    ]);

                    $stats[$affected ? 'assigned' : 'kept']++;
                }
            });
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Biriktirildi: {$stats['assigned']} · qoʼlda qoʼyilgani saqlandi: {$stats['kept']}");
        $this->comment("   Zaif (< {$min}) oʼtkazib yuborildi: {$stats['weak']} · tozalandi: {$stats['cleared']} · nomaʼlum slug: {$stats['unknown']} · bazada yoʼq soʼz: {$stats['missing']}");

        return self::SUCCESS;
    }
}
