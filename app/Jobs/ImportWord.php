<?php

namespace App\Jobs;

use App\Services\Dictionary\DictionaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches a word the dictionary has never seen, off the request path.
 *
 * A search that misses used to call the third-party dictionary right there,
 * with a twelve-second timeout, and then the translator — every mistyped or
 * half-typed word held a PHP worker for that long, and with five workers a
 * teacher filling a stage could stall the whole app. Now the search answers
 * from our own table at once and the import happens here; the next search
 * for the same spelling finds the word.
 */
class ImportWord implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public string $query) {}

    public function uniqueId(): string
    {
        return md5(strtolower(trim($this->query)));
    }

    public function handle(DictionaryService $dictionary): void
    {
        $word = $dictionary->lookup($this->query);

        if ($word) {
            $dictionary->translate($word);

            return;
        }

        // Remembered so the same spelling is not looked up again for a while.
        Cache::put(self::missKey($this->query), true, now()->addHours(6));
    }

    public static function missKey(string $query): string
    {
        return 'dict:miss:'.md5(strtolower(trim($query)));
    }
}
