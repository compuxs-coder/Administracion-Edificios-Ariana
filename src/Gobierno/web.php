<?php

use Illuminate\Support\Facades\Route;
use Src\Gobierno\Application\Controllers\AuditoriaWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('gobierno/auditoria', [AuditoriaWebController::class, 'index'])
        ->middleware('throttle:120,1,gobierno-auditoria')
        ->name('gobierno.auditoria.index');
    Route::post('gobierno/auditoria/exportaciones', [AuditoriaWebController::class, 'storeExport'])
        ->middleware('throttle:10,1,gobierno-exportaciones')
        ->name('gobierno.auditoria.exportaciones.store');
    Route::get('gobierno/auditoria/exportaciones/{exportacion}/descargar', [AuditoriaWebController::class, 'downloadExport'])
        ->whereUuid('exportacion')
        ->middleware('throttle:30,1,gobierno-exportaciones-descarga')
        ->name('gobierno.auditoria.exportaciones.download');
});
