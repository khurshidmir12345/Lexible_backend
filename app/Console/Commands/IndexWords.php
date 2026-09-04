<?php

namespace App\Console\Commands;

use App\Models\Word;
use App\Services\Dictionary\SearchIndex;
use Illuminate\Console\Command;

class IndexWords extends Command
{
    protected $signature = 'words:index';

    protected $description = 'Rebuild the word search index (run after any bulk change to words)';

    public function handle(SearchIndex $index): int
    {
        $bar = $this->output->createProgressBar(Word::count());
        $bar->start();

        $started = microtime(true);
        $done = $index->rebuildAll(fn (int $n) => $bar->advance($n));

        $bar->finish();
        $this->newLine();
        $this->info(sprintf('✅ %d ta soʼz indekslandi (%.0f s)', $done, microtime(true) - $started));

        return self::SUCCESS;
    }
}
