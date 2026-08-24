<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bot credentials
    |--------------------------------------------------------------------------
    */

    'token' => env('TELEGRAM_BOT_TOKEN'),
    'username' => env('TELEGRAM_BOT_USERNAME'),
    'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),

    // Local development only: treat this Telegram id as the signed-in player so
    // the Mini App can be opened in a plain browser without Telegram's initData.
    'dev_user_id' => env('TELEGRAM_DEV_USER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | The secret is sent by Telegram in the X-Telegram-Bot-Api-Secret-Token
    | header on every update, so we can reject forged requests.
    */

    'webhook' => [
        'path' => env('TELEGRAM_WEBHOOK_PATH', 'telegram/webhook'),
        'secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'allowed_updates' => ['message', 'callback_query', 'my_chat_member', 'pre_checkout_query'],
        'log_updates' => (bool) env('TELEGRAM_LOG_UPDATES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mini App
    |--------------------------------------------------------------------------
    | init_data_ttl: how long (seconds) a signed initData payload stays valid.
    | short_name:    the /newapp short name, used to build t.me direct links.
    */

    'mini_app' => [
        'url' => env('TELEGRAM_MINI_APP_URL', env('APP_URL').'/app'),
        'short_name' => env('TELEGRAM_MINI_APP_SHORT_NAME', 'game'),
        'init_data_ttl' => (int) env('TELEGRAM_INIT_DATA_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting limits (Telegram: ~30 messages/second global)
    |--------------------------------------------------------------------------
    */

    'broadcast' => [
        'per_second' => (int) env('TELEGRAM_BROADCAST_PER_SECOND', 25),
        'chunk' => (int) env('TELEGRAM_BROADCAST_CHUNK', 100),
    ],

];
