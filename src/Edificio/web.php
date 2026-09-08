<?php

use Illuminate\Support\Facades\Route;
use Src\Edificio\Application\Controllers\DepartamentoWebController;
use Src\Edificio\Application\Controllers\AccesoEdificioWebController;
use Src\Edificio\Application\Controllers\EdificioWebController;
use Src\Edificio\Application\Controllers\EstructuraWebController;

Route::middleware('auth')->group(function () {
    Route::get('invitaciones-edificio/{token}', [AccesoEdificioWebController::class, 'showInvitation'])
        ->name('invitaciones-edificio.show');
    Route::post('invitaciones-edificio/{token}/aceptar', [AccesoEdificioWebController::class, 'acceptInvitation'])
        ->name('invitaciones-edificio.accept');

    Route::get('departamentos', [DepartamentoWebController::class, 'index'])
        ->name('departamentos.index');
    Route::get('departamentos/create', [DepartamentoWebController::class, 'create'])
        ->name('departamentos.create');

    Route::get('edificios/{edificio}/estructura', [EstructuraWebController::class, 'index'])
        ->name('edificios.estructura');
    Route::post('edificios/{edificio}/torres', [EstructuraWebController::class, 'storeTorre'])
        ->name('edificios.torres.store');
    Route::put('edificios/{edificio}/torres/{torre}', [EstructuraWebController::class, 'updateTorre'])
        ->name('edificios.torres.update');
    Route::post('edificios/{edificio}/pisos', [EstructuraWebController::class, 'storePiso'])
        ->name('edificios.pisos.store');
    Route::put('edificios/{edificio}/pisos/{piso}', [EstructuraWebController::class, 'updatePiso'])
        ->name('edificios.pisos.update');
    Route::post('edificios/{edificio}/parqueaderos', [EstructuraWebController::class, 'storeParqueadero'])
        ->name('edificios.parqueaderos.store');
    Route::put('edificios/{edificio}/parqueaderos/{parqueadero}', [EstructuraWebController::class, 'updateParqueadero'])
        ->name('edificios.parqueaderos.update');
    Route::post('edificios/{edificio}/bodegas', [EstructuraWebController::class, 'storeBodega'])
        ->name('edificios.bodegas.store');
    Route::put('edificios/{edificio}/bodegas/{bodega}', [EstructuraWebController::class, 'updateBodega'])
        ->name('edificios.bodegas.update');
    Route::patch('edificios/{edificio}/estructura/{tipo}/{elemento}/estado', [EstructuraWebController::class, 'changeElementStatus'])
        ->whereIn('tipo', ['torre', 'piso', 'parqueadero', 'bodega'])
        ->name('edificios.estructura.estado');

    Route::post('edificios/{edificio}/departamentos', [DepartamentoWebController::class, 'store'])
        ->name('departamentos.store');
    Route::get('edificios/{edificio}/departamentos/{departamento}', [DepartamentoWebController::class, 'show'])
        ->whereUuid('edificio')
        ->whereUuid('departamento')
        ->name('departamentos.show');
    Route::get('edificios/{edificio}/departamentos/{departamento}/edit', [DepartamentoWebController::class, 'edit'])
        ->name('departamentos.edit');
    Route::put('edificios/{edificio}/departamentos/{departamento}', [DepartamentoWebController::class, 'update'])
        ->name('departamentos.update');
    Route::patch('edificios/{edificio}/departamentos/{departamento}/estado', [DepartamentoWebController::class, 'changeStatus'])
        ->name('departamentos.estado');

    Route::patch('edificios/{edificio}/estado', [EdificioWebController::class, 'changeStatus'])
        ->name('edificios.estado');
    Route::get('edificios/{edificio}/accesos', [AccesoEdificioWebController::class, 'index'])
        ->name('edificios.accesos.index');
    Route::post('edificios/{edificio}/invitaciones', [AccesoEdificioWebController::class, 'invite'])
        ->name('edificios.invitaciones.store');
    Route::patch('edificios/{edificio}/invitaciones/{invitacion}/revocar', [AccesoEdificioWebController::class, 'revokeInvitation'])
        ->whereUuid('invitacion')
        ->name('edificios.invitaciones.revoke');
    Route::put('edificios/{edificio}/miembros/{usuario}/roles', [AccesoEdificioWebController::class, 'updateRoles'])
        ->whereUuid('usuario')
        ->name('edificios.miembros.roles.update');
    Route::delete('edificios/{edificio}/miembros/{usuario}', [AccesoEdificioWebController::class, 'revokeMember'])
        ->whereUuid('usuario')
        ->name('edificios.miembros.revoke');
    Route::resource('edificios', EdificioWebController::class)
        ->except('destroy');
});
