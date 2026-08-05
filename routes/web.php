<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

// Rutas protegidas (autenticadas)
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
