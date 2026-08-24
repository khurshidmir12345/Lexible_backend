<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Test types
    |--------------------------------------------------------------------------
    | The six exercises a player can pick before a round. Each one is also a
    | mastery dimension stored on `word_progress` as `m_<key>`.
    */

    'test_types' => ['card', 'uz2en', 'en2uz', 'spell', 'image', 'match'],

    'session' => [
        'choice_options' => 4,       // buttons in a multiple-choice question
        'match_pairs' => 6,          // pairs shown in one matching round
        'requeue_wrong' => true,     // a missed word comes back at the end of the queue
    ],

    /*
    |--------------------------------------------------------------------------
    | Mastery
    |--------------------------------------------------------------------------
    | Each correct answer raises that dimension, each miss lowers it. A word
    | counts as learned once its six-dimension average crosses `learned_at`.
    */

    'mastery' => [
        'learned_at' => 70,          // green threshold, also "weak word" cutoff
        'mid_at' => 40,              // amber threshold
        'gain_on_correct' => 20,
        'loss_on_wrong' => 15,
        'max' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coins
    |--------------------------------------------------------------------------
    | Earned by practising, spent on premium paths. The home screen states the
    | rates out loud, so they live here rather than being scattered in code.
    */

    'coins' => [
        'per_correct' => 1,
        'per_duel_win' => 10,
        'per_referral' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Road map
    |--------------------------------------------------------------------------
    | Nodes are created ahead of the player so the map always looks like a
    | journey. Every `exam_every`-th node is an exam checkpoint.
    */

    'road' => [
        'initial_nodes' => 8,
        'lookahead' => 4,            // keep this many locked nodes past the current one
        'exam_every' => 4,
        'min_words_to_complete' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Duels
    |--------------------------------------------------------------------------
    */

    'duel' => [
        'lobby_ttl_minutes' => 15,
        'countdown_seconds' => 3,
        'poll_interval_ms' => 1500,  // how often the client refreshes the live score
    ],

];
