<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WordResource;
use App\Services\Dictionary\DictionaryService;
use Illuminate\Http\Request;

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

        // Nothing stored yet, and it looks like an English word: go and get it.
        if ($results->isEmpty() && $query !== '' && preg_match('/^[a-zA-Z\- ]{2,40}$/', $query)) {
            if ($imported = $dictionary->lookup($query)) {
                $dictionary->translate($imported);
                $results = $dictionary->search($query, $locale);
            }
        }

        return [
            'words' => WordResource::collection($results)->toArray($request),
            'imported' => $results->isNotEmpty() && $query !== '',
        ];
    }
}
