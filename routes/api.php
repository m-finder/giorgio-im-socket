<?php

use GiorgioImSocket\Http\Controllers\ImController;
use GiorgioImSocket\Http\Controllers\UploadController;
use GiorgioImSocket\Http\Middleware\EnsureImUser;
use GiorgioSocket\Http\Middleware\RequireSecureTransport;
use Illuminate\Support\Facades\Route;

Route::prefix(config('im.routes.prefix', 'api/im'))->middleware([...config('im.routes.middleware', ['api']), RequireSecureTransport::class.':im'])->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api')->group(function () {
    Route::get('files/{upload}', [UploadController::class, 'show'])->middleware(['signed', 'throttle:im-files'])->name('im.files.show');
    Route::middleware([...config('im.routes.auth_middleware', ['auth']), EnsureImUser::class, 'throttle:im-api'])->group(function () {
        Route::get('init', [ImController::class, 'init']);
        Route::post('socket-ticket', [ImController::class, 'ticket']);
        Route::get('events', [ImController::class, 'events']);
        Route::patch('user', [ImController::class, 'profile']);
        Route::get('users', [ImController::class, 'find']);
        Route::post('friend-groups', [ImController::class, 'categories']);
        Route::post('friend-requests', [ImController::class, 'requestFriend']);
        Route::patch('friends/{friend}/group', [ImController::class, 'moveFriend']);
        Route::delete('friends/{friend}', [ImController::class, 'removeFriend']);
        Route::get('blocks', [ImController::class, 'blocks']);
        Route::post('blocks/{user}', [ImController::class, 'block']);
        Route::delete('blocks/{user}', [ImController::class, 'unblock']);
        Route::post('notices/{notice}', [ImController::class, 'notice']);
        Route::post('groups', [ImController::class, 'createGroup']);
        Route::patch('groups/{group}', [ImController::class, 'groupProfile']);
        Route::delete('groups/{group}', [ImController::class, 'dismiss']);
        Route::get('groups/{group}/members', [ImController::class, 'members']);
        Route::post('groups/{group}/members', [ImController::class, 'invite']);
        Route::post('groups/{group}/leave', [ImController::class, 'leave']);
        Route::delete('groups/{group}/members/{member}', [ImController::class, 'removeMember']);
        Route::patch('groups/{group}/notifications', [ImController::class, 'notifications']);
        Route::patch('groups/{group}/mute', [ImController::class, 'mute']);
        Route::patch('groups/{group}/members/{member}/mute', [ImController::class, 'mute']);
        Route::patch('groups/{group}/members/{member}/admin', [ImController::class, 'admin']);
        Route::get('messages', [ImController::class, 'history']);
        Route::post('messages', [ImController::class, 'send']);
        Route::post('messages/read', [ImController::class, 'read']);
        Route::post('conversations/typing', [ImController::class, 'typing']);
        Route::post('uploads', [UploadController::class, 'store']);
    });
});
