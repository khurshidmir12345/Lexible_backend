<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lookup pipeline
    |--------------------------------------------------------------------------
    | A word is always served from our `words` table. On a miss we enrich it
    | once from the free providers below, store the result, and every later
    | lookup of that word is a plain database read.
    */

    'cache_ttl' => 60 * 60 * 24,        // seconds a lookup result stays in the cache layer
    'enrich_on_miss' => env('DICT_ENRICH_ON_MISS', true),
    'queue_enrichment' => env('DICT_QUEUE_ENRICHMENT', true),

    /*
    |--------------------------------------------------------------------------
    | English data — dictionaryapi.dev
    |--------------------------------------------------------------------------
    | Free, no API key. Gives IPA, pronunciation audio, part of speech,
    | definitions, example sentences and synonyms.
    */

    'definitions' => [
        'url' => 'https://api.dictionaryapi.dev/api/v2/entries/en/',
        'timeout' => 12,
        'delay_ms' => 250,               // be polite when seeding in bulk
    ],

    /*
    |--------------------------------------------------------------------------
    | Translations — MyMemory
    |--------------------------------------------------------------------------
    | Free tier: ~1000 words/day anonymously, 50 000/day when `email` is set.
    | Karakalpak (kaa) is not supported by any free engine — those translations
    | are filled in by an admin from the panel.
    */

    'translations' => [
        'url' => 'https://api.mymemory.translated.net/get',
        'timeout' => 12,
        'delay_ms' => 400,
        'email' => env('MYMEMORY_EMAIL'),   // raises the daily quota
        'variants' => 4,                    // how many alternatives to keep per language
        'min_match' => 0.60,                // ignore low-confidence alternatives
    ],

    /*
    |--------------------------------------------------------------------------
    | Translations — Claude
    |--------------------------------------------------------------------------
    | The bulk dictionary is translated by Claude rather than a translation
    | endpoint, because a word is only translatable once you know which sense
    | is meant. Every request carries the part of speech, the English
    | definition and an example, which is what stops "home" coming back as
    | "bosh sahifa".
    */

    'claude' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('DICT_CLAUDE_MODEL', 'claude-opus-5'),
        'max_tokens' => 8000,
        'batch_size' => 40,          // words per request
        'max_attempts' => 3,         // before a word is parked as failed
    ],

    /*
    |--------------------------------------------------------------------------
    | Translations — Gemini
    |--------------------------------------------------------------------------
    | The bulk of the dictionary goes through Gemini: on the words that trip a
    | translator up ("felt" the cloth, "spoke" the part of a wheel) it matched
    | hand-written translations 19 times out of 20, at roughly a tenth of the
    | price of the alternatives.
    */

    'translator' => env('DICT_TRANSLATOR', 'gemini'),   // gemini | claude

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('DICT_GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'max_tokens' => 8192,
        'timeout' => 120,
        'batch_size' => 40,          // words per request
        'retries' => 5,
        'backoff_ms' => 3000,        // multiplied by the attempt number
        'delay_ms' => 500,           // pause between batches, to stay under the quota
    ],

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    | `auto` are fetched from the translation API; `manual` must be typed by an
    | admin because no free engine covers them.
    */

    'languages' => [
        'auto' => ['uz', 'ru', 'kk', 'ky'],
        'manual' => ['kaa'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeding
    |--------------------------------------------------------------------------
    | Public-domain frequency list used to decide which words to import first.
    */

    'seed' => [
        'wordlist_url' => 'https://raw.githubusercontent.com/first20hours/google-10000-english/master/google-10000-english-usa-no-swears.txt',
        'min_length' => 3,

        // A vocabulary game should teach content words. Grammar words like
        // "the" or "of" top every frequency list but make useless flashcards,
        // and their dictionary entries are obscure ("of: to possess, own").
        'keep_pos' => ['noun', 'verb', 'adjective', 'adverb', 'interjection'],

        'skip_words' => [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 'was',
            'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how', 'its', 'may', 'new',
            'now', 'old', 'see', 'two', 'who', 'boy', 'did', 'she', 'use', 'way', 'about',
            'that', 'this', 'with', 'they', 'from', 'have', 'were', 'been', 'their', 'said',
            'each', 'which', 'them', 'then', 'there', 'these', 'those', 'what', 'when', 'your',
            'would', 'could', 'should', 'shall', 'will', 'been', 'being', 'does', 'doing',
            'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'between', 'through',
            'during', 'before', 'after', 'because', 'while', 'where', 'whom', 'whose', 'why',
            'here', 'than', 'such', 'some', 'more', 'most', 'other', 'another', 'any', 'both',
            'few', 'many', 'much', 'own', 'same', 'too', 'very', 'just', 'only', 'also',
            'him', 'his', 'hers', 'ours', 'yours', 'theirs', 'myself', 'himself', 'herself',
            'itself', 'ourselves', 'yourself', 'themselves', 'don', 'doesn', 'didn', 'isn',
            'aren', 'wasn', 'weren', 'hasn', 'haven', 'hadn', 'won', 'wouldn', 'couldn',
            'shouldn', 'mustn', 'inc', 'llc', 'etc', 'www', 'com', 'net', 'org', 'http',
        ],
    ],

];
