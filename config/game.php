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
        'per_word_mastered' => 5,
        'per_duel_win' => 10,
        'per_group_duel_win' => 20,
        'per_referral' => 50,

        // Coins turn into Premium on their own; each tier is reached once and
        // adds its days. Measured against lifetime earnings, never the balance.
        'premium_tiers' => [
            ['coins' => 300, 'days' => 3],
            ['coins' => 500, 'days' => 3],
            ['coins' => 1000, 'days' => 7],
        ],
    ],

    'premium' => [
        'price_label' => '19 000 soʼm',
        'period_label' => '/ oy',
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
    | Teaching
    |--------------------------------------------------------------------------
    | A stage is meant to be one lesson's worth of vocabulary; the number of
    | stages in a path is deliberately unlimited.
    */

    'teaching' => [
        'max_words_per_stage' => 20,
        'stage_unlock_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exams
    |--------------------------------------------------------------------------
    | An exam node has no vocabulary of its own: it draws at random from every
    | stage before it, which is what makes it a checkpoint rather than another
    | lesson.
    */

    'exam' => [
        'questions' => 9,
        'pass_mark' => 70,
        'types' => ['uz2en', 'en2uz', 'spell'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Duels
    |--------------------------------------------------------------------------
    */

    'competition' => [
        'lobby_ttl_minutes' => 60,
        'types' => ['uz2en', 'en2uz'],
        'poll_interval_ms' => 2000,
    ],

    'duel' => [
        'lobby_ttl_minutes' => 15,
        'countdown_seconds' => 3,
        'poll_interval_ms' => 1500,  // how often the client refreshes the live score
    ],

];
