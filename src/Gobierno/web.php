<?php

use Illuminate\Support\Facades\Route;
use Src\Gobierno\Application\Controllers\AuditoriaWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('gobierno/auditoria', [AuditoriaWebController::class, 'index'])
        ->middleware('throttle:120,1,gobierno-auditoria')
        ->name('gobierno.auditoria.index');
});
