<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\RoadController;
use App\Http\Controllers\Api\TestController;
use App\Http\Controllers\Api\WordSearchController;
use Illuminate\Support\Facades\Route;

/*
| Every route below is authenticated by the Telegram initData signature the
| Mini App sends in the X-Telegram-Init-Data header. There is no login route:
| opening the app inside Telegram is the login.
*/

Route::middleware('miniapp')->group(function () {
    Route::get('/me', [MeController::class, 'show']);
    Route::post('/onboarding', [MeController::class, 'onboard']);
    Route::patch('/me', [MeController::class, 'update']);

    Route::get('/dashboard', DashboardController::class);
    Route::get('/road', RoadController::class);

    Route::get('/words/search', WordSearchController::class);

    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::patch('/categories/{category}', [CategoryController::class, 'rename']);
    Route::post('/categories/{category}/words', [CategoryController::class, 'attach']);
    Route::delete('/categories/{category}/words/{word}', [CategoryController::class, 'detach']);

    Route::post('/categories/{category}/tests', [TestController::class, 'start']);
    Route::post('/tests/{session}/answer', [TestController::class, 'answer']);
    Route::post('/tests/{session}/finish', [TestController::class, 'finish']);
});
