<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SeedDictionary extends Command
{
    protected $signature = 'dictionary:seed
        {--limit=500   : How many words from the frequency list to import}
        {--offset=0    : Skip this many words — lets you resume a long import}
        {--file=       : Import from a local newline-separated file instead of the frequency list}';

    protected $description = 'Import English words (IPA, audio, definition, example) from the free dictionary API';

    public function handle(DictionaryService $dictionary): int
    {
        $words = $this->wordList();

        if ($words->isEmpty()) {
            $this->error('No source words found.');

            return self::FAILURE;
        }

        $words = $words
            ->skip((int) $this->option('offset'))
            ->take((int) $this->option('limit'))
            ->values();

        // Skip what we already stored so a re-run costs nothing.
        $known = Word::whereIn('normalized', $words->all())->pluck('normalized')->flip();
        $pending = $words->reject(fn ($w) => $known->has($w))->values();

        $this->info("{$words->count()} ta so'z tanlandi, {$pending->count()} tasi yangi.");

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        $imported = $skipped = 0;
        $rank = (int) $this->option('offset');

        foreach ($pending as $word) {
            $rank++;

            try {
                $dictionary->import($word, $rank) ? $imported++ : $skipped++;
            } catch (\Throwable $e) {
                $skipped++;
                $this->newLine();
                $this->warn("{$word}: {$e->getMessage()}");
            }

            usleep(config('dictionary.definitions.delay_ms') * 1000);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Qo'shildi: {$imported} · O'tkazib yuborildi (lug'atda yo'q): {$skipped}");
        $this->line("Jami bazada: ".Word::count()." ta so'z");
        $this->comment("Keyingi qadam: php artisan dictionary:translate");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    protected function wordList()
    {
        if ($file = $this->option('file')) {
            return collect(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
                ->map(fn ($w) => Str::lower(trim($w)))
                ->filter()
                ->unique()
                ->values();
        }

        $this->line('Chastota ro\'yxati yuklanmoqda...');

        $response = Http::timeout(30)->get(config('dictionary.seed.wordlist_url'));

        if (! $response->successful()) {
            $this->error('Word list could not be downloaded.');

            return collect();
        }

        $min = config('dictionary.seed.min_length');
        $skip = array_flip(config('dictionary.seed.skip_words', []));

        return collect(preg_split('/\R/', $response->body()))
            ->map(fn ($w) => Str::lower(trim($w)))
            ->filter(fn ($w) => $w !== ''
                && Str::length($w) >= $min
                && preg_match('/^[a-z]+$/', $w)
                && ! isset($skip[$w]))
            ->unique()
            ->values();
    }
}
