<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\EmojiMatcher;
use Illuminate\Console\Command;

class AssignEmoji extends Command
{
    protected $signature = 'dictionary:emoji
        {--all : Re-assign every word, not only the ones without an emoji}';

    protected $description = 'Give each word an illustrative emoji (stand-in until 3D icons are in place)';

    public function handle(EmojiMatcher $matcher): int
    {
        $query = Word::query()->when(! $this->option('all'), fn ($q) => $q->whereNull('emoji'));
        $total = $query->count();

        if ($total === 0) {
            $this->info('Barcha so\'zlarda emoji bor ✅');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $matched = $missed = 0;

        $query->chunkById(200, function ($words) use ($matcher, $bar, &$matched, &$missed) {
            foreach ($words as $word) {
                $emoji = $matcher->match($word->word, $word->part_of_speech);
                $emoji ? $matched++ : $missed++;

                $word->updateQuietly(['emoji' => $emoji]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Emoji topildi: {$matched} · Topilmadi: {$missed}");
        $this->comment('Topilmaganlarga ilovada 📘 zaxira belgisi ko\'rsatiladi.');

        return self::SUCCESS;
    }
}
