<?php

use App\Http\Controllers\GYZController;
use Illuminate\Support\Facades\Route;

//GYZ 模块
// AI 智能问答
Route::prefix('chat')->group(function () {
    Route::post('message',               [GYZController::class, 'chatMessage']);
    Route::get('test',                   [GYZController::class, 'chatTest']);
    Route::get('health',                 [GYZController::class, 'chatHealth']);
    Route::get('welcome',                [GYZController::class, 'chatWelcome']);
    Route::get('sessions',               [GYZController::class, 'chatSessions'])->middleware('auth:api');
    Route::get('sessions/{sessionId}/messages', [GYZController::class, 'chatSessionMessages'])->middleware('auth:api');
    Route::delete('sessions/{sessionId}', [GYZController::class, 'chatDeleteSession'])->middleware('auth:api');
    Route::delete('sessions',            [GYZController::class, 'chatClearSessions'])->middleware('auth:api');
});

// 文创商城
Route::prefix('shop')->group(function () {
    Route::get('categories',        [GYZController::class, 'shopCategories']);
    Route::get('products',          [GYZController::class, 'shopProducts']);
    Route::get('products/{id}',     [GYZController::class, 'shopProductDetail']);
    Route::post('orders',           [GYZController::class, 'shopOrderCreate'])->middleware('auth:api');
    Route::get('orders',            [GYZController::class, 'shopOrders'])->middleware('auth:api');
});

// 消息通知
Route::prefix('notifications')->middleware('auth:api')->group(function () {
    Route::get('/',                  [GYZController::class, 'notificationList']);
    Route::get('unread-count',       [GYZController::class, 'notificationUnreadCount']);
    Route::post('{id}/read',         [GYZController::class, 'notificationRead']);
    Route::post('read-all',          [GYZController::class, 'notificationReadAll']);
    Route::delete('{id}',            [GYZController::class, 'notificationDelete']);
    Route::delete('read',            [GYZController::class, 'notificationClearRead']);
});

// 线上轻互动（小游戏）
Route::prefix('games')->group(function () {
    Route::get('/',                  [GYZController::class, 'gameList']);
    Route::get('scores/my',          [GYZController::class, 'gameScoresMy'])->middleware('auth:api');
    Route::post('scores',            [GYZController::class, 'gameScoreSubmit'])->middleware('auth:api');
    Route::get('scores/{id}/certificate', [GYZController::class, 'gameCertificate'])->middleware('auth:api');

    // 特定游戏路径 — 必须放在 {type} 前面避免路由冲突
    Route::get('drawing/levels/{id}/pattern',  [GYZController::class, 'gamePattern']);
    Route::get('coloring/templates/{id}',       [GYZController::class, 'gameTemplate']);

    Route::get('{type}',                    [GYZController::class, 'gameDetail']);
    Route::get('{type}/levels',             [GYZController::class, 'gameLevels']);
    Route::get('{type}/leaderboard',        [GYZController::class, 'gameLeaderboard']);
    Route::get('{type}/levels/{id}/best',   [GYZController::class, 'gameBestScore'])->middleware('auth:api');
});
