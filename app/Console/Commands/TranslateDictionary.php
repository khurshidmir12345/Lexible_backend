<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\Contracts\Translator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fills in the Uzbek for words the import left pending.
 *
 * Four hundred thousand words is not one job — it is a job that runs many
 * times, survives being interrupted, and is worth stopping early once the
 * words people actually use are done. So the state lives on each row
 * (`translation_status`), the queue is ordered by how common a word is, and
 * every run is bounded by `--limit`.
 */
class TranslateDictionary extends Command
{
    protected $signature = 'dictionary:translate
        {--limit=500 : How many words to translate in this run}
        {--max-rank= : Only words this common or commoner (e.g. 20000)}
        {--ranked : Only words that appear in the frequency list at all}
        {--retry : Include words that failed on an earlier run}
        {--word=* : Translate these words specifically, ignoring the queue}
        {--lang=uz : Languages to fill, comma separated (uz,ru)}
        {--dry : Show what would be sent, call nothing}';

    protected $description = 'Translate pending dictionary words into Uzbek with Claude';

    public function handle(Translator $translator): int
    {
        $words = $this->queue();

        if ($words->isEmpty()) {
            $this->info('Tarjima kutayotgan soʼz yoʼq ✅');

            return self::SUCCESS;
        }

        $locales = array_values(array_filter(array_map('trim', explode(',', $this->option('lang')))));
        $driver = config('dictionary.translator');
        $size = (int) config("dictionary.{$driver}.batch_size", 40);
        $chunks = $words->chunk($size);

        $this->info(sprintf(
            '%s ta soʼz · %s soʼrov · %s (%s) · tillar: %s',
            number_format($words->count()),
            number_format($chunks->count()),
            $translator->name(),
            config("dictionary.{$driver}.model"),
            implode(', ', $locales),
        ));

        if ($this->option('dry')) {
            $this->preview($words->take(12));

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($chunks->count());
        $bar->start();

        $done = $missed = $failed = 0;

        foreach ($chunks as $chunk) {
            try {
                $result = $translator->translate($chunk->map(fn (Word $w) => [
                    'word' => $w->word,
                    'pos' => $w->part_of_speech,
                    'gloss' => $w->definition['en'] ?? null,
                    'example' => $w->example['en'] ?? null,
                ])->values()->all(), $locales);

                [$ok, $blank] = $this->store($chunk, $result, $locales, $translator->name());
                $done += $ok;
                $missed += $blank;
            } catch (Throwable $e) {
                $failed += $chunk->count();
                $this->markFailed($chunk, $e->getMessage());
                $this->newLine();
                $this->warn('  '.mb_substr($e->getMessage(), 0, 140));
            }

            $bar->advance();

            if ($delay = (int) config("dictionary.{$driver}.delay_ms")) {
                usleep($delay * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ {$done} ta tarjima qilindi");
        if ($missed) {
            $this->line("   {$missed} ta uchun tarjima qaytmadi");
        }
        if ($failed) {
            $this->line("   {$failed} ta soʼrov xato bilan tugadi — `--retry` bilan qayta urinib koʼring");
        }

        $left = Word::where('translation_status', 'pending')->count();
        $this->line('   qolgan: '.number_format($left));

        foreach ($locales as $locale) {
            $this->line(sprintf('   %s tarjimasi bor: %s', $locale, number_format(
                Word::whereNotNull('translations->'.$locale)->count(),
            )));
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Word> */
    protected function queue()
    {
        if ($names = $this->option('word')) {
            return Word::whereIn('normalized', array_map('strtolower', $names))->get();
        }

        $statuses = $this->option('retry') ? ['pending', 'failed'] : ['pending'];

        return Word::query()
            ->whereIn('translation_status', $statuses)
            ->where('is_active', true)
            ->where('translation_attempts', '<', config('dictionary.claude.max_attempts'))
            ->when($this->option('ranked'), fn ($q) => $q->whereNotNull('frequency_rank'))
            ->when($this->option('max-rank'), fn ($q, $rank) => $q->where('frequency_rank', '<=', (int) $rank))
            // Commonest first, and words a learner can actually be taught
            // ahead of grammar words — a run that stops early should have
            // spent its budget where it shows.
            ->orderByRaw('is_teachable desc, frequency_rank is null, frequency_rank')
            ->limit((int) $this->option('limit'))
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Word>  $chunk
     * @param  array<string, list<string>>  $result
     * @return array{0: int, 1: int} translated, left blank
     */
    protected function store($chunk, array $result, array $locales, string $source): array
    {
        $done = $blank = 0;

        foreach ($chunk as $word) {
            $byLocale = $result[$word->word] ?? [];

            if ($byLocale === []) {
                // Claude saw it and had nothing — not a transport failure, so
                // it is parked rather than retried forever.
                $word->forceFill([
                    'translation_status' => 'blank',
                    'translation_attempts' => $word->translation_attempts + 1,
                ])->saveQuietly();
                $blank++;

                continue;
            }

            $translations = $word->translations ?? [];

            foreach ($byLocale as $locale => $forms) {
                $translations[$locale] = $forms;
            }

            $word->forceFill([
                'translations' => $translations,
                'translation_status' => 'done',
                'translation_source' => $source,
                'translated_at' => now(),
                'translation_attempts' => $word->translation_attempts + 1,
                'needs_review' => false,
            ])->saveQuietly();

            $done++;
        }

        return [$done, $blank];
    }

    /** @param \Illuminate\Support\Collection<int, Word> $chunk */
    protected function markFailed($chunk, string $reason): void
    {
        Word::whereIn('id', $chunk->pluck('id'))->update([
            'translation_status' => 'failed',
            'translation_attempts' => \Illuminate\Support\Facades\DB::raw('translation_attempts + 1'),
        ]);
    }

    /** @param \Illuminate\Support\Collection<int, Word> $words */
    protected function preview($words): void
    {
        $this->newLine();
        $this->line('Claude ga shunday yuboriladi:');
        $this->newLine();

        foreach ($words as $word) {
            $line = $word->word;

            if ($word->part_of_speech) {
                $line .= ' ('.$word->part_of_speech.')';
            }
            if ($gloss = $word->definition['en'] ?? null) {
                $line .= ' — '.mb_substr($gloss, 0, 90);
            }

            $this->line('  '.$line);
        }
    }
}
