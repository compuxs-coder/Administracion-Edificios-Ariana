<?php

use Illuminate\Support\Facades\Route;
use Src\Operaciones\Application\Controllers\MantenimientoPreventivoWebController;
use Src\Operaciones\Application\Controllers\OrdenOperativaWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('operaciones', [OrdenOperativaWebController::class, 'index'])->name('operaciones.index');
    Route::get('operaciones/create', [OrdenOperativaWebController::class, 'create'])->name('operaciones.create');
    Route::post('edificios/{edificio}/operaciones', [OrdenOperativaWebController::class, 'store'])->whereUuid('edificio')->name('operaciones.store');
    Route::get('edificios/{edificio}/operaciones/{orden}', [OrdenOperativaWebController::class, 'show'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.show');
    Route::get('edificios/{edificio}/operaciones/{orden}/edit', [OrdenOperativaWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.edit');
    Route::put('edificios/{edificio}/operaciones/{orden}', [OrdenOperativaWebController::class, 'update'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.update');
    Route::patch('edificios/{edificio}/operaciones/{orden}/estado', [OrdenOperativaWebController::class, 'transition'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.transition');
    Route::post('edificios/{edificio}/operaciones/{orden}/asignaciones', [OrdenOperativaWebController::class, 'assign'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.assign');
    Route::patch('edificios/{edificio}/operaciones/{orden}/cancelar', [OrdenOperativaWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.cancel');
    Route::patch('edificios/{edificio}/operaciones/{orden}/reabrir', [OrdenOperativaWebController::class, 'reopen'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.reopen');
    Route::post('edificios/{edificio}/operaciones/{orden}/actuaciones', [OrdenOperativaWebController::class, 'storeAction'])->whereUuid('edificio')->whereUuid('orden')->name('operaciones.actions.store');
    Route::post('edificios/{edificio}/operaciones/{orden}/evidencias', [OrdenOperativaWebController::class, 'storeEvidence'])->middleware('throttle:5,1')->whereUuid('edificio')->whereUuid('orden')->name('operaciones.evidence.store');
    Route::get('edificios/{edificio}/operaciones/{orden}/evidencias/{evidencia}/download', [OrdenOperativaWebController::class, 'downloadEvidence'])->middleware('throttle:60,1')->whereUuid('edificio')->whereUuid('orden')->whereUuid('evidencia')->name('operaciones.evidence.download');

    Route::get('mantenimiento-preventivo', [MantenimientoPreventivoWebController::class, 'index'])->name('mantenimiento-preventivo.index');
    Route::get('mantenimiento-preventivo/create', [MantenimientoPreventivoWebController::class, 'create'])->name('mantenimiento-preventivo.create');
    Route::post('edificios/{edificio}/mantenimiento-preventivo', [MantenimientoPreventivoWebController::class, 'store'])->whereUuid('edificio')->name('mantenimiento-preventivo.store');
    Route::get('edificios/{edificio}/mantenimiento-preventivo/{plan}', [MantenimientoPreventivoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('plan')->name('mantenimiento-preventivo.show');
    Route::get('edificios/{edificio}/mantenimiento-preventivo/{plan}/edit', [MantenimientoPreventivoWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('plan')->name('mantenimiento-preventivo.edit');
    Route::put('edificios/{edificio}/mantenimiento-preventivo/{plan}', [MantenimientoPreventivoWebController::class, 'update'])->whereUuid('edificio')->whereUuid('plan')->name('mantenimiento-preventivo.update');
    Route::patch('edificios/{edificio}/mantenimiento-preventivo/{plan}/estado', [MantenimientoPreventivoWebController::class, 'changeStatus'])->whereUuid('edificio')->whereUuid('plan')->name('mantenimiento-preventivo.status');
    Route::post('edificios/{edificio}/mantenimiento-preventivo/{plan}/ocurrencias/{ocurrencia}/reintentar', [MantenimientoPreventivoWebController::class, 'retry'])->whereUuid('edificio')->whereUuid('plan')->whereUuid('ocurrencia')->name('mantenimiento-preventivo.occurrences.retry');
    Route::patch('edificios/{edificio}/mantenimiento-preventivo/{plan}/ocurrencias/{ocurrencia}/omitir', [MantenimientoPreventivoWebController::class, 'omit'])->whereUuid('edificio')->whereUuid('plan')->whereUuid('ocurrencia')->name('mantenimiento-preventivo.occurrences.omit');
});
