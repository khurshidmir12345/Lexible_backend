<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CoinController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DuelController;
use App\Http\Controllers\Api\GroupJoinController;
use App\Http\Controllers\Api\CompetitionController;
use App\Http\Controllers\Api\Teacher\CompetitionController as TeacherCompetitionController;
use App\Http\Controllers\Api\Teacher\GroupController as TeacherGroupController;
use App\Http\Controllers\Api\Teacher\PathController;
use App\Http\Controllers\Api\Teacher\PlanController;
use App\Http\Controllers\Api\LearnedWordsController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
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
    Route::post('/me/role', [MeController::class, 'chooseRole']);
    Route::get('/me/impact', [MeController::class, 'impact']);
    Route::delete('/me', [MeController::class, 'destroy']);

    Route::get('/dashboard', DashboardController::class);
    Route::get('/coins', [CoinController::class, 'show']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read', [NotificationController::class, 'markRead']);
    Route::get('/streak', [CoinController::class, 'streak']);
    Route::get('/road', RoadController::class);

    Route::get('/words/search', WordSearchController::class);
    Route::get('/learned', LearnedWordsController::class);

    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::patch('/categories/{category}', [CategoryController::class, 'rename']);
    Route::post('/categories/{category}/words', [CategoryController::class, 'attach']);
    Route::delete('/categories/{category}/words/{word}', [CategoryController::class, 'detach']);

    Route::get('/categories/{category}/exam', [TestController::class, 'briefing']);
    Route::post('/categories/{category}/tests', [TestController::class, 'start']);

    Route::post('/categories/{category}/duels', [DuelController::class, 'store']);
    Route::get('/duels/{code}', [DuelController::class, 'show']);
    Route::post('/duels/{code}/join', [DuelController::class, 'join']);
    Route::post('/duels/{code}/play', [DuelController::class, 'play']);
    Route::post('/duels/{code}/finish', [DuelController::class, 'finish']);

    // Groups a student belongs to
    Route::post('/groups/join', [GroupJoinController::class, 'join']);
    Route::get('/groups/mine', [GroupJoinController::class, 'mine']);
    Route::delete('/groups/{group}/leave', [GroupJoinController::class, 'leave']);

    // Everything below is the teacher side of the app
    Route::prefix('teacher')->group(function () {
        Route::get('/dashboard', [TeacherGroupController::class, 'dashboard']);
        Route::get('/profile', [TeacherGroupController::class, 'profile']);

        Route::get('/plan', [PlanController::class, 'show']);
        Route::post('/plan', [PlanController::class, 'choose']);
        Route::post('/plan/mode', [PlanController::class, 'billingMode']);
        Route::post('/plan/remind', [PlanController::class, 'remind']);

        Route::get('/paths', [PathController::class, 'index']);
        Route::post('/paths', [PathController::class, 'store']);
        Route::patch('/paths/{path}', [PathController::class, 'update']);
        Route::delete('/paths/{path}', [PathController::class, 'destroy']);
        Route::post('/paths/{path}/stages', [PathController::class, 'addStage']);
        Route::get('/stages/{stage}', [PathController::class, 'showStage']);
        Route::patch('/stages/{stage}', [PathController::class, 'updateStage']);
        Route::delete('/stages/{stage}', [PathController::class, 'destroyStage']);

        Route::get('/groups', [TeacherGroupController::class, 'index']);
        Route::post('/groups', [TeacherGroupController::class, 'store']);
        Route::get('/groups/{group}', [TeacherGroupController::class, 'show']);
        Route::patch('/groups/{group}', [TeacherGroupController::class, 'update']);
        Route::delete('/groups/{group}', [TeacherGroupController::class, 'destroy']);
        Route::patch('/groups/{group}/path', [TeacherGroupController::class, 'attachPath']);
        Route::get('/groups/{group}/road', [TeacherGroupController::class, 'road']);
        Route::get('/groups/{group}/stages/{stage}/results', [TeacherGroupController::class, 'stageResults']);
        Route::get('/groups/{group}/candidates', [TeacherGroupController::class, 'searchStudents']);
        Route::post('/groups/{group}/members', [TeacherGroupController::class, 'addStudent']);

        Route::get('/competitions', [TeacherCompetitionController::class, 'mine']);
        Route::get('/groups/{group}/competitions', [TeacherCompetitionController::class, 'index']);
        Route::post('/groups/{group}/competitions', [TeacherCompetitionController::class, 'store']);
        Route::post('/stages/{stage}/competitions', [TeacherCompetitionController::class, 'open']);
        Route::get('/competitions/{competition}', [TeacherCompetitionController::class, 'show']);
        Route::post('/competitions/{competition}/start', [TeacherCompetitionController::class, 'start']);
        Route::post('/competitions/{competition}/close', [TeacherCompetitionController::class, 'close']);
        Route::get('/competitions/{competition}/results', [TeacherCompetitionController::class, 'results']);

        Route::post('/members/{member}/approve', [TeacherGroupController::class, 'approve']);
        Route::delete('/members/{member}', [TeacherGroupController::class, 'remove']);
    });
    Route::get('/competitions/{code}', [CompetitionController::class, 'show']);
    Route::post('/competitions/{code}/join', [CompetitionController::class, 'join']);
    Route::post('/competitions/{code}/session', [CompetitionController::class, 'session']);
    Route::post('/competitions/{code}/finish', [CompetitionController::class, 'finish']);
    Route::get('/competitions/{code}/results', [CompetitionController::class, 'results']);

    Route::post('/tests/{session}/answer', [TestController::class, 'answer']);
    Route::post('/tests/{session}/finish', [TestController::class, 'finish']);
});
