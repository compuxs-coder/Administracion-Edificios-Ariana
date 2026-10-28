<?php

use Illuminate\Support\Facades\Route;
use Src\Auth\Application\Controllers\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->middleware('abilities:token:revoke');
        Route::get('me', [AuthController::class, 'me'])->middleware('abilities:user:read');
    });
});
