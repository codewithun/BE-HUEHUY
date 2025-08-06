<?php

use App\Http\Controllers\Integration\UnparOneController;
use Illuminate\Support\Facades\Route;


Route::prefix('/unparone-integration')->group(function() {

    Route::post('/login', [UnparOneController::class, 'login']);
    Route::post('/register', [UnparOneController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function() {

        Route::get('/account', [UnparOneController::class, 'account']);

        Route::get('/cube', [UnparOneController::class, 'getCube']);
        Route::get('/primary-category', [UnparOneController::class, 'getPrimaryCategory']);
        Route::get('/ad-category', [UnparOneController::class, 'getAdCategory']);
        Route::get('/grab', [UnparOneController::class, 'getGrab']);
        Route::post('/grab', [UnparOneController::class, 'storeGrab']);

        // * Picklist
        Route::get('/picklist/world', [UnparOneController::class, 'picklistWorld']);
    });
});