<?php

use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// Telegram pushes updates here. The secret header is checked by the middleware.
Route::post(config('telegram.webhook.path'), TelegramWebhookController::class)
    ->middleware('telegram.webhook');

// The Mini App shell. Everything inside it talks to /api.
Route::get('/app', MiniAppController::class)->name('miniapp');

Route::get('/', fn () => redirect('/app'));
