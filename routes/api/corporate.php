<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Corporate\AdController;
use App\Http\Controllers\Corporate\ChatController;
use App\Http\Controllers\Corporate\CubeController;
use App\Http\Controllers\Corporate\DashboardController;
use App\Http\Controllers\Corporate\GrabController;
use App\Http\Controllers\Corporate\NotificationController;
use App\Http\Controllers\Corporate\OpeningHourController;
use App\Http\Controllers\Corporate\UserController;
use App\Http\Controllers\Corporate\VoucherController;
use App\Http\Controllers\Corporate\WorldController;

use App\Http\Controllers\PicklistController;

Route::prefix('corporate')->name('corporate.')->group(function () {

    // * Picklist Options
    Route::prefix('/options')->group(function () {
        Route::get('/role', [PicklistController::class, 'role']);
        Route::get('/user', [PicklistController::class, 'user']);
        Route::get('/cube', [PicklistController::class, 'cube']);
        Route::get('/cube-type', [PicklistController::class, 'cubeType']);
        Route::get('/ad-category', [PicklistController::class, 'adCategory']);
        Route::get('/world', [PicklistController::class, 'world']);
    });

    Route::get('/dashboard/counter-data', [DashboardController::class, 'counterData']);

    // Corporate-specific account endpoint with full relations
    Route::get('/account', [App\Http\Controllers\AuthController::class, 'corporateAccount']);
    Route::get('/profile', [App\Http\Controllers\AuthController::class, 'corporateAccount']); // Alias untuk frontend

    Route::apiResource('/users', UserController::class);
    Route::post('/users-new', [UserController::class, 'createNew']);

    Route::apiResource('/worlds', WorldController::class)->only('index', 'update');
    Route::get('/worlds/{id}/user', [WorldController::class, 'getWorldMember']);
    Route::post('/worlds/{id}/user', [WorldController::class, 'addWorldMember']);
    Route::post('/worlds/{id}/user-new', [WorldController::class, 'createWorldMember']);
    Route::delete('/worlds/{id}/user/{userWorldId}', [WorldController::class, 'destroyWorldMember']);

    Route::apiResource('/cubes', CubeController::class);
    Route::post('/cubes/create-gift', [CubeController::class, 'createGiftCube']);
    Route::apiResource('/ads', AdController::class);

    Route::get('/grabs', [GrabController::class, 'index']);

    Route::apiResource('/vouchers', VoucherController::class);

    Route::post('/opening-hours', [OpeningHourController::class, 'store']);
    Route::put('/opening-hours/{id}', [OpeningHourController::class, 'update']);
    Route::delete('/opening-hours/{id}', [OpeningHourController::class, 'destroy']);

    Route::get('/chat-rooms', [ChatController::class, 'index']);
    // Route::post('/chat-rooms', [ChatController::class, 'store']);
    Route::get('/chat-rooms/{id}', [ChatController::class, 'show']);
    Route::post('/chats', [ChatController::class, 'createMessage']);

    Route::get('/notification', [NotificationController::class, 'index']);

    // Corporate Community Management
    Route::apiResource('/communities', \App\Http\Controllers\Corporate\CommunityController::class);
    Route::get('/communities/{id}/members', [\App\Http\Controllers\Corporate\CommunityController::class, 'getMembers'])->whereNumber('id');
    Route::post('/communities/{id}/members', [\App\Http\Controllers\Corporate\CommunityController::class, 'addMember'])->whereNumber('id');
    Route::get('/communities/{id}/member-requests', [\App\Http\Controllers\Corporate\CommunityController::class, 'getMemberRequests'])->whereNumber('id');
    Route::post('/member-requests/{id}/approve', [\App\Http\Controllers\Corporate\CommunityController::class, 'approveMemberRequest'])->whereNumber('id');
    Route::post('/member-requests/{id}/reject', [\App\Http\Controllers\Corporate\CommunityController::class, 'rejectMemberRequest'])->whereNumber('id');
    Route::get('/communities/{id}/member-history', [\App\Http\Controllers\Corporate\CommunityController::class, 'getMemberHistory'])->whereNumber('id');
    Route::get('/communities/{id}/cubes', [\App\Http\Controllers\Corporate\CommunityController::class, 'cubes'])->whereNumber('id');
    Route::delete('/communities/{communityId}/members/{userId}', [\App\Http\Controllers\Corporate\CommunityController::class, 'removeMember'])
        ->whereNumber('communityId')
        ->whereNumber('userId');
});
