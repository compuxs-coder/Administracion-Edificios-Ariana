<?php

use Illuminate\Support\Facades\Route;
use Src\Propiedad\Application\Controllers\PropietarioWebController;
use Src\Propiedad\Application\Controllers\TitularidadWebController;
use Src\Propiedad\Application\Controllers\ResidenteWebController;
use Src\Propiedad\Application\Controllers\OcupacionWebController;

Route::middleware('auth')->group(function () {
    Route::get('propietarios', [PropietarioWebController::class, 'index'])
        ->name('propietarios.index');
    Route::get('propietarios/create', [PropietarioWebController::class, 'create'])
        ->name('propietarios.create');
    Route::post('edificios/{edificio}/propietarios', [PropietarioWebController::class, 'store'])
        ->whereUuid('edificio')
        ->name('propietarios.store');
    Route::get('propietarios/{propietario}', [PropietarioWebController::class, 'show'])
        ->whereUuid('propietario')
        ->name('propietarios.show');
    Route::get('propietarios/{propietario}/edit', [PropietarioWebController::class, 'edit'])
        ->whereUuid('propietario')
        ->name('propietarios.edit');
    Route::put('propietarios/{propietario}', [PropietarioWebController::class, 'update'])
        ->whereUuid('propietario')
        ->name('propietarios.update');
    Route::patch('propietarios/{propietario}/estado', [PropietarioWebController::class, 'changeStatus'])
        ->whereUuid('propietario')
        ->name('propietarios.estado');

    Route::post('edificios/{edificio}/departamentos/{departamento}/propietarios', [TitularidadWebController::class, 'assign'])
        ->whereUuid('edificio')
        ->whereUuid('departamento')
        ->name('departamentos.propietarios.assign');
    Route::patch('edificios/{edificio}/departamentos/{departamento}/propietarios/{titularidad}/finalizar', [TitularidadWebController::class, 'finalize'])
        ->whereUuid('departamento')
        ->whereUuid('titularidad')
        ->whereUuid('edificio')
        ->name('departamentos.propietarios.finalize');
    Route::post('edificios/{edificio}/departamentos/{departamento}/propietarios/transferir', [TitularidadWebController::class, 'transfer'])
        ->whereUuid('edificio')
        ->whereUuid('departamento')
        ->name('departamentos.propietarios.transfer');

    Route::get('residentes', [ResidenteWebController::class, 'index'])
        ->name('residentes.index');
    Route::get('residentes/create', [ResidenteWebController::class, 'create'])
        ->name('residentes.create');
    Route::post('edificios/{edificio}/residentes', [ResidenteWebController::class, 'store'])
        ->whereUuid('edificio')
        ->name('residentes.store');
    Route::get('residentes/{residente}', [ResidenteWebController::class, 'show'])
        ->whereUuid('residente')
        ->name('residentes.show');
    Route::get('residentes/{residente}/edit', [ResidenteWebController::class, 'edit'])
        ->whereUuid('residente')
        ->name('residentes.edit');
    Route::put('residentes/{residente}', [ResidenteWebController::class, 'update'])
        ->whereUuid('residente')
        ->name('residentes.update');
    Route::patch('residentes/{residente}/estado', [ResidenteWebController::class, 'changeStatus'])
        ->whereUuid('residente')
        ->name('residentes.estado');

    Route::post('edificios/{edificio}/departamentos/{departamento}/residentes', [OcupacionWebController::class, 'assign'])
        ->whereUuid('edificio')
        ->whereUuid('departamento')
        ->name('departamentos.residentes.assign');
    Route::patch('edificios/{edificio}/departamentos/{departamento}/residentes/{ocupacion}/finalizar', [OcupacionWebController::class, 'finalize'])
        ->whereUuid('edificio')
        ->whereUuid('departamento')
        ->whereUuid('ocupacion')
        ->name('departamentos.residentes.finalize');
});
