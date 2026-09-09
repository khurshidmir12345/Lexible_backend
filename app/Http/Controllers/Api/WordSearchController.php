<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WordResource;
use App\Jobs\ImportWord;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WordSearchController extends Controller
{
    /**
     * The "add words" screen. Results come from our own table; a word we have
     * never seen is fetched from the dictionary API once and stored, so the
     * next player who searches it gets an instant answer.
     */
    public function __invoke(Request $request, DictionaryService $dictionary): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'lang' => ['nullable', 'in:en,native'],
        ]);

        $query = trim($data['q'] ?? '');
        $locale = $request->user()->native_lang;

        $results = $dictionary->search($query, $locale);

        // Nothing stored yet, and it looks like a whole English word: have it
        // fetched — in the background, once per spelling. The answer here is
        // whatever the table holds right now; the request never waits on the
        // network, so a mistyped word costs nobody a stalled worker.
        if ($results->isEmpty() && $this->worthLookingUp($query)) {
            ImportWord::dispatch($query);
        }

        return [
            'words' => WordResource::collection($results)->toArray($request),
            'imported' => false,
        ];
    }

    protected function worthLookingUp(string $query): bool
    {
        return config('dictionary.enrich_on_miss')
            && strlen($query) >= 3
            && preg_match('/^[a-zA-Z][a-zA-Z\- ]{1,39}$/', $query)
            && ! Cache::has(ImportWord::missKey($query));
    }
}
