<?php

use Illuminate\Support\Facades\Route;
use Src\Finanzas\Application\Controllers\CargoWebController;
use Src\Finanzas\Application\Controllers\CarteraWebController;
use Src\Finanzas\Application\Controllers\ConceptoCobroWebController;
use Src\Finanzas\Application\Controllers\PagoWebController;

Route::middleware('auth')->group(function () {
    Route::get('cargos', [CargoWebController::class, 'index'])->name('cargos.index');
    Route::get('cargos/generar', [CargoWebController::class, 'generate'])->name('cargos.generate');
    Route::get('cargos/create', [CargoWebController::class, 'create'])->name('cargos.create');
    Route::post('edificios/{edificio}/cargos/generar', [CargoWebController::class, 'storeGeneration'])->whereUuid('edificio')->name('cargos.generate.store');
    Route::post('edificios/{edificio}/cargos', [CargoWebController::class, 'storeManual'])->whereUuid('edificio')->name('cargos.store');
    Route::get('edificios/{edificio}/cargos/{cargo}', [CargoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('cargo')->name('cargos.show');
    Route::patch('edificios/{edificio}/cargos/{cargo}/anular', [CargoWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('cargo')->name('cargos.cancel');
    Route::get('pagos', [PagoWebController::class, 'index'])->name('pagos.index');
    Route::get('pagos/create', [PagoWebController::class, 'create'])->name('pagos.create');
    Route::post('edificios/{edificio}/pagos', [PagoWebController::class, 'store'])->whereUuid('edificio')->name('pagos.store');
    Route::get('edificios/{edificio}/pagos/{pago}', [PagoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('pago')->name('pagos.show');
    Route::post('edificios/{edificio}/pagos/{pago}/aplicar-saldo-favor', [PagoWebController::class, 'applyCredit'])->whereUuid('edificio')->whereUuid('pago')->name('pagos.apply-credit');
    Route::patch('edificios/{edificio}/pagos/{pago}/anular', [PagoWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('pago')->name('pagos.cancel');
    Route::get('cartera', [CarteraWebController::class, 'index'])->name('cartera.index');
    Route::get('edificios/{edificio}/departamentos/{departamento}/estado-cuenta', [CarteraWebController::class, 'show'])->whereUuid('edificio')->whereUuid('departamento')->name('cartera.show');
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
