<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\Providers\ClaudeTranslator;
use Anthropic\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * The same translation, sent through the Batches API.
 *
 * Four hundred thousand words is ten thousand requests. Sent one at a time
 * that is a script babysat for hours; sent as a batch it is one submission,
 * a wait, and one collection — at half the price. The trade is that results
 * arrive later, which for a dictionary nobody is waiting on is no trade at all.
 *
 *   php artisan dictionary:translate-batch --limit=50000    # submit
 *   php artisan dictionary:translate-batch --collect        # ingest when ready
 */
class TranslateDictionaryBatch extends Command
{
    protected $signature = 'dictionary:translate-batch
        {--limit=20000 : How many words to submit in this batch}
        {--max-rank= : Only words this common or commoner}
        {--collect : Fetch results for batches already submitted}
        {--status : Show what is in flight}';

    protected $description = 'Translate the dictionary through the Claude Batches API (half price)';

    /** Where submitted batch ids and their word lists are remembered. */
    protected function dir(): string
    {
        $dir = storage_path('app/dictionary/batches');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public function handle(ClaudeTranslator $translator): int
    {
        if ($this->option('status')) {
            return $this->status();
        }

        if ($this->option('collect')) {
            return $this->collect($translator);
        }

        return $this->submit($translator);
    }

    // ----------------------------------------------------------------- submit

    protected function submit(ClaudeTranslator $translator): int
    {
        $size = (int) config('dictionary.claude.batch_size');

        $words = Word::query()
            ->where('translation_status', 'pending')
            ->where('is_active', true)
            ->where('translation_attempts', '<', config('dictionary.claude.max_attempts'))
            ->when($this->option('max-rank'), fn ($q, $r) => $q->where('frequency_rank', '<=', (int) $r))
            ->orderByRaw('is_teachable desc, frequency_rank is null, frequency_rank')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($words->isEmpty()) {
            $this->info('Tarjima kutayotgan soʼz yoʼq ✅');

            return self::SUCCESS;
        }

        $requests = [];
        $covered = [];

        foreach ($words->chunk($size) as $index => $chunk) {
            $id = 'w'.$index;

            $requests[] = [
                'customId' => $id,
                'params' => $translator->batchParams($chunk->map(fn (Word $w) => [
                    'word' => $w->word,
                    'pos' => $w->part_of_speech,
                    'gloss' => $w->definition['en'] ?? null,
                    'example' => $w->example['en'] ?? null,
                ])->values()->all()),
            ];

            $covered[$id] = $chunk->pluck('id')->all();
        }

        $this->info(sprintf('%s ta soʼz · %s soʼrov yuborilmoqda…',
            number_format($words->count()), number_format(count($requests))));

        try {
            $batch = $this->client()->messages->batches->create(requests: $requests);
        } catch (Throwable $e) {
            $this->error('Yuborib boʼlmadi: '.$e->getMessage());

            return self::FAILURE;
        }

        File::put($this->dir()."/{$batch->id}.json", json_encode([
            'id' => $batch->id,
            'submitted_at' => now()->toIso8601String(),
            'words' => $words->count(),
            'covered' => $covered,
        ], JSON_UNESCAPED_UNICODE));

        // Marked so a second submission cannot queue the same words twice.
        Word::whereIn('id', $words->pluck('id'))->update(['translation_status' => 'queued']);

        $this->newLine();
        $this->info("✅ Yuborildi: {$batch->id}");
        $this->line('   Natijani olish uchun: php artisan dictionary:translate-batch --collect');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- collect

    protected function collect(ClaudeTranslator $translator): int
    {
        $files = glob($this->dir().'/*.json');

        if (! $files) {
            $this->info('Kutilayotgan partiya yoʼq.');

            return self::SUCCESS;
        }

        foreach ($files as $path) {
            $record = json_decode(File::get($path), true);
            $id = $record['id'];

            try {
                $batch = $this->client()->messages->batches->retrieve($id);
            } catch (Throwable $e) {
                $this->warn("{$id}: {$e->getMessage()}");

                continue;
            }

            if ($batch->processingStatus !== 'ended') {
                $this->line("  {$id}: {$batch->processingStatus} — hali tayyor emas");

                continue;
            }

            $this->info("{$id}: tayyor, oʼqilmoqda…");
            $done = $blank = $failed = 0;

            foreach ($this->client()->messages->batches->results($id) as $result) {
                $ids = $record['covered'][$result->customId] ?? [];
                $chunk = Word::whereIn('id', $ids)->get();

                if ($chunk->isEmpty()) {
                    continue;
                }

                if ($result->result->type !== 'succeeded') {
                    $this->markFailed($chunk);
                    $failed += $chunk->count();

                    continue;
                }

                $text = '';
                foreach ($result->result->message->content as $block) {
                    if ($block->type === 'text') {
                        $text .= $block->text;
                    }
                }

                try {
                    $parsed = $translator->parse($text, $chunk->map(fn (Word $w) => ['word' => $w->word])->all());
                } catch (Throwable $e) {
                    $this->markFailed($chunk);
                    $failed += $chunk->count();

                    continue;
                }

                [$ok, $empty] = $this->store($chunk, $parsed);
                $done += $ok;
                $blank += $empty;
            }

            $this->line("   ✅ {$done} tarjima · {$blank} boʼsh · {$failed} xato");
            File::delete($path);
        }

        $left = Word::whereIn('translation_status', ['pending', 'queued'])->count();
        $this->newLine();
        $this->line('Qolgan: '.number_format($left));

        return self::SUCCESS;
    }

    protected function status(): int
    {
        foreach (glob($this->dir().'/*.json') as $path) {
            $record = json_decode(File::get($path), true);

            try {
                $batch = $this->client()->messages->batches->retrieve($record['id']);
                $this->line(sprintf('  %s · %s soʼz · %s',
                    $record['id'], number_format($record['words']), $batch->processingStatus));
            } catch (Throwable $e) {
                $this->warn("  {$record['id']}: {$e->getMessage()}");
            }
        }

        foreach (['pending', 'queued', 'done', 'blank', 'failed'] as $status) {
            $this->line(sprintf('  %-8s %s', $status, number_format(
                Word::where('translation_status', $status)->count(),
            )));
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ store

    /**
     * @param  \Illuminate\Support\Collection<int, Word>  $chunk
     * @param  array<string, list<string>>  $result
     * @return array{0: int, 1: int}
     */
    protected function store($chunk, array $result): array
    {
        $done = $blank = 0;

        foreach ($chunk as $word) {
            $uz = $result[$word->word] ?? [];

            if ($uz === []) {
                $word->forceFill([
                    'translation_status' => 'blank',
                    'translation_attempts' => $word->translation_attempts + 1,
                ])->saveQuietly();
                $blank++;

                continue;
            }

            $translations = $word->translations ?? [];
            $translations['uz'] = $uz;

            $word->forceFill([
                'translations' => $translations,
                'translation_status' => 'done',
                'translation_source' => 'claude',
                'translated_at' => now(),
                'translation_attempts' => $word->translation_attempts + 1,
                'needs_review' => false,
            ])->saveQuietly();

            $done++;
        }

        return [$done, $blank];
    }

    /** @param \Illuminate\Support\Collection<int, Word> $chunk */
    protected function markFailed($chunk): void
    {
        Word::whereIn('id', $chunk->pluck('id'))->update([
            'translation_status' => 'failed',
            'translation_attempts' => \Illuminate\Support\Facades\DB::raw('translation_attempts + 1'),
        ]);
    }

    protected function client(): Client
    {
        $key = config('dictionary.claude.key');

        if (! $key) {
            throw new RuntimeException('ANTHROPIC_API_KEY oʼrnatilmagan — .env fayliga qoʼshing.');
        }

        return new Client(apiKey: $key);
    }
}
