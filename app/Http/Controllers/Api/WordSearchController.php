<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WordResource;
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

        // Nothing stored yet, and it looks like a whole English word: go and
        // get it — but only once per spelling. Typing "bea", "beau", "beaut"
        // used to fire three slow dictionary calls that all came back empty.
        if ($results->isEmpty() && $this->worthLookingUp($query)) {
            if ($imported = $dictionary->lookup($query)) {
                $dictionary->translate($imported);
                $results = $dictionary->search($query, $locale);
            } else {
                Cache::put($this->missKey($query), true, now()->addHours(6));
            }
        }

        return [
            'words' => WordResource::collection($results)->toArray($request),
            'imported' => $results->isNotEmpty() && $query !== '',
        ];
    }

    protected function worthLookingUp(string $query): bool
    {
        return strlen($query) >= 3
            && preg_match('/^[a-zA-Z][a-zA-Z\- ]{1,39}$/', $query)
            && ! Cache::has($this->missKey($query));
    }

    protected function missKey(string $query): string
    {
        return 'dict:miss:'.md5(strtolower($query));
    }
}
