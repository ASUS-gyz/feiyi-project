<?php

use App\Http\Controllers\CGJController;
use App\Http\Controllers\GYZController;
use App\Http\Controllers\WLJController;
use Illuminate\Support\Facades\Route;

// 认证模块
Route::prefix('auth')->group(function () {
    Route::post('/register', [CGJController::class, 'register']);
    Route::post('/login', [CGJController::class, 'login']);
    Route::post('/logout', [CGJController::class, 'logout']);
    Route::get('/me', [CGJController::class, 'me'])->middleware('jwt.auth');
});

// 用户资料模块
Route::prefix('users')->middleware('jwt.auth')->group(function () {
    Route::put('/me', [CGJController::class, 'updateProfile']);
    Route::post('/me/password', [CGJController::class, 'updatePassword']);
});

// 文件上传模块
Route::prefix('upload')->group(function () {
    Route::post('/avatar', [CGJController::class, 'uploadAvatar'])->middleware('jwt.auth');
    Route::post('/post-image', [CGJController::class, 'uploadPostImage']);
});

// 传承基地模块
Route::prefix('bases')->group(function () {
    Route::get('/nearby', [CGJController::class, 'baseNearby']);
    Route::get('/', [CGJController::class, 'baseList']);
    Route::get('{id}', [CGJController::class, 'baseDetail'])->whereNumber('id');
});

// 展览活动模块
Route::prefix('events')->group(function () {
    Route::get('/', [CGJController::class, 'eventList']);
    Route::get('{id}/calendar', [CGJController::class, 'eventCalendar'])->whereNumber('id');
    Route::get('{id}', [CGJController::class, 'eventDetail'])->whereNumber('id');
});

// 捐赠支持模块
Route::prefix('donations')->group(function () {
    Route::get('/projects', [CGJController::class, 'donationProjects']);
    Route::post('/', [CGJController::class, 'createDonation'])->middleware('jwt.auth');
    Route::get('/records', [CGJController::class, 'donationRecords'])->middleware('jwt.auth');
    Route::get('{id}/certificate', [CGJController::class, 'donationCertificate'])->middleware('jwt.auth')->whereNumber('id');
});

//GYZ 模块
// AI 智能问答
Route::prefix('chat')->group(function () {
    Route::post('message',               [GYZController::class, 'chatMessage'])->middleware('jwt.optional');
    Route::get('test',                   [GYZController::class, 'chatTest']);
    Route::get('health',                 [GYZController::class, 'chatHealth']);
    Route::get('welcome',                [GYZController::class, 'chatWelcome']);
    Route::get('sessions',               [GYZController::class, 'chatSessions'])->middleware('jwt.auth');
    Route::get('sessions/{sessionId}/messages', [GYZController::class, 'chatSessionMessages'])->middleware('jwt.auth');
    Route::delete('sessions/{sessionId}', [GYZController::class, 'chatDeleteSession'])->middleware('jwt.auth');
    Route::delete('sessions',            [GYZController::class, 'chatClearSessions'])->middleware('jwt.auth');
});

// 文创商城
Route::prefix('shop')->group(function () {
    Route::get('categories',        [GYZController::class, 'shopCategories']);
    Route::get('products',          [GYZController::class, 'shopProducts']);
    Route::get('products/{id}',     [GYZController::class, 'shopProductDetail']);
    Route::post('orders',           [GYZController::class, 'shopOrderCreate'])->middleware('jwt.auth');
    Route::get('orders',            [GYZController::class, 'shopOrders'])->middleware('jwt.auth');
});

// 消息通知
Route::prefix('notifications')->middleware('jwt.auth')->group(function () {
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
    Route::get('scores/my',          [GYZController::class, 'gameScoresMy'])->middleware('jwt.auth');
    Route::post('scores',            [GYZController::class, 'gameScoreSubmit'])->middleware('jwt.auth');
    Route::get('scores/{id}/certificate', [GYZController::class, 'gameCertificate'])->middleware('jwt.auth');

    // 特定游戏路径 — 必须放在 {type} 前面避免路由冲突
    Route::get('drawing/levels/{id}/pattern',  [GYZController::class, 'gamePattern']);
    Route::get('coloring/templates/{id}',       [GYZController::class, 'gameTemplate']);

    Route::get('{type}',                    [GYZController::class, 'gameDetail']);
    Route::get('{type}/levels',             [GYZController::class, 'gameLevels']);
    Route::get('{type}/leaderboard',        [GYZController::class, 'gameLeaderboard']);
    Route::get('{type}/levels/{id}/best',   [GYZController::class, 'gameBestScore'])->middleware('jwt.auth');
});

// ==================== WLJ 模块 ====================

// 互动帖子模块
Route::prefix('posts')->group(function () {
    Route::get('/',          [WLJController::class, 'postList'])->name('posts.list');
    Route::get('{id}',       [WLJController::class, 'postDetail'])->name('posts.detail')->whereNumber('id');
    Route::post('/',         [WLJController::class, 'postCreate'])->middleware('jwt.auth')->name('posts.create');
    Route::put('{id}',       [WLJController::class, 'postUpdate'])->middleware('jwt.auth')->name('posts.update')->whereNumber('id');
    Route::delete('{id}',    [WLJController::class, 'postDelete'])->middleware('jwt.auth')->name('posts.delete')->whereNumber('id');
});

// 评论与回复模块
Route::prefix('comments')->group(function () {
    Route::get('post/{postId}',   [WLJController::class, 'commentByPost'])->name('comments.byPost')->whereNumber('postId');
    Route::get('/',               [WLJController::class, 'commentList'])->name('comments.list');
    Route::post('/',              [WLJController::class, 'commentCreate'])->name('comments.create');
    Route::put('{id}',            [WLJController::class, 'commentUpdate'])->middleware('jwt.auth')->name('comments.update')->whereNumber('id');
    Route::delete('{id}',         [WLJController::class, 'commentDelete'])->middleware('jwt.auth')->name('comments.delete')->whereNumber('id');
    Route::post('{id}/like',      [WLJController::class, 'commentLike'])->name('comments.like')->whereNumber('id');
    Route::delete('{id}/like',    [WLJController::class, 'commentUnlike'])->name('comments.unlike')->whereNumber('id');
});

// 传世名作模块
Route::prefix('masterpieces')->group(function () {
    Route::get('/',             [WLJController::class, 'masterpieceList'])->name('masterpieces.list');
    Route::get('{id}',          [WLJController::class, 'masterpieceDetail'])->name('masterpieces.detail')->whereNumber('id');
    Route::post('{id}/like',    [WLJController::class, 'masterpieceLike'])->middleware('jwt.auth')->name('masterpieces.like')->whereNumber('id');
    Route::delete('{id}/like',  [WLJController::class, 'masterpieceUnlike'])->middleware('jwt.auth')->name('masterpieces.unlike')->whereNumber('id');
});

// 收藏夹模块
Route::prefix('favorites')->middleware('jwt.auth')->group(function () {
    Route::get('/',          [WLJController::class, 'favoriteList'])->name('favorites.list');
    Route::get('check',      [WLJController::class, 'favoriteCheck'])->name('favorites.check');
    Route::post('/',         [WLJController::class, 'favoriteAdd'])->name('favorites.add');
    Route::delete('/',       [WLJController::class, 'favoriteDelete'])->name('favorites.deleteByTarget');
    Route::delete('{id}',    [WLJController::class, 'favoriteDelete'])->name('favorites.delete')->whereNumber('id');
});

// 共创计划模块
Route::prefix('cooperations')->group(function () {
    Route::get('submissions/my',  [WLJController::class, 'cooperationMySubmissions'])->middleware('jwt.auth')->name('cooperations.mySubmissions');
    Route::get('/',               [WLJController::class, 'cooperationList'])->name('cooperations.list');
    Route::get('{id}',            [WLJController::class, 'cooperationDetail'])->name('cooperations.detail')->whereNumber('id');
    Route::post('{id}/submissions', [WLJController::class, 'cooperationSubmit'])->middleware('jwt.auth')->name('cooperations.submit')->whereNumber('id');
});
