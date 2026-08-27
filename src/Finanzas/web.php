<?php

use Illuminate\Support\Facades\Route;
use Src\Finanzas\Application\Controllers\ConceptoCobroWebController;

Route::middleware('auth')->group(function () {
    Route::get('conceptos', [ConceptoCobroWebController::class, 'index'])
        ->name('conceptos.index');
    Route::get('conceptos/create', [ConceptoCobroWebController::class, 'create'])
        ->name('conceptos.create');
    Route::post('edificios/{edificio}/conceptos', [ConceptoCobroWebController::class, 'store'])
        ->whereUuid('edificio')
        ->name('conceptos.store');
    Route::get('edificios/{edificio}/conceptos/{concepto}', [ConceptoCobroWebController::class, 'show'])
        ->whereUuid('edificio')
        ->whereUuid('concepto')
        ->name('conceptos.show');
    Route::get('edificios/{edificio}/conceptos/{concepto}/edit', [ConceptoCobroWebController::class, 'edit'])
        ->whereUuid('edificio')
        ->whereUuid('concepto')
        ->name('conceptos.edit');
    Route::put('edificios/{edificio}/conceptos/{concepto}', [ConceptoCobroWebController::class, 'update'])
        ->whereUuid('edificio')
        ->whereUuid('concepto')
        ->name('conceptos.update');
    Route::patch('edificios/{edificio}/conceptos/{concepto}/estado', [ConceptoCobroWebController::class, 'changeStatus'])
        ->whereUuid('edificio')
        ->whereUuid('concepto')
        ->name('conceptos.estado');
    Route::post('edificios/{edificio}/conceptos/{concepto}/tarifas', [ConceptoCobroWebController::class, 'storeTarifa'])
        ->whereUuid('edificio')
        ->whereUuid('concepto')
        ->name('conceptos.tarifas.store');
});
