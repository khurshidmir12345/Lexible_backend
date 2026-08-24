<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Console\Command;

class TranslateDictionary extends Command
{
    protected $signature = 'dictionary:translate
        {--limit=200 : How many words to translate in this run}
        {--lang=     : Only this language (uz, ru, kk, ky)}';

    protected $description = 'Fill missing uz/ru/kk/ky translations for words already in the database';

    public function handle(DictionaryService $dictionary): int
    {
        $locales = $this->option('lang')
            ? [$this->option('lang')]
            : config('dictionary.languages.auto');

        // A word still needs work if any target language is missing.
        $pending = Word::query()
            ->where('is_active', true)
            ->where(function ($q) use ($locales) {
                foreach ($locales as $locale) {
                    $q->orWhereNull('translations->'.$locale);
                }
            })
            ->orderBy('frequency_rank')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Barcha so\'zlar tarjima qilingan ✅');

            return self::SUCCESS;
        }

        $this->info("{$pending->count()} ta so'z tarjima qilinadi: ".implode(', ', $locales));
        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        $done = $empty = 0;

        foreach ($pending as $word) {
            $before = $word->translations ?? [];
            $dictionary->translate($word, $locales);

            ($word->fresh()->translations ?? []) === $before ? $empty++ : $done++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Tarjima qilindi: {$done} · Bo'sh qaytdi: {$empty}");

        if ($empty > $done) {
            $this->warn('Ko\'p so\'rov bo\'sh qaytdi — MyMemory kunlik limiti tugagan bo\'lishi mumkin.');
            $this->comment('MYMEMORY_EMAIL ni .env ga qo\'ysangiz limit 50 000/kun ga ko\'tariladi.');
        }

        return self::SUCCESS;
    }
}
