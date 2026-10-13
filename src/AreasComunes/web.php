<?php

use Illuminate\Support\Facades\Route;
use Src\AreasComunes\Application\Controllers\EspacioComunWebController;
use Src\AreasComunes\Application\Controllers\ReservaAreaComunWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('areas-comunes', [EspacioComunWebController::class, 'index'])->name('areas-comunes.index');
    Route::get('areas-comunes/create', [EspacioComunWebController::class, 'create'])->name('areas-comunes.create');
    Route::post('edificios/{edificio}/areas-comunes', [EspacioComunWebController::class, 'store'])->whereUuid('edificio')->name('areas-comunes.store');
    Route::get('edificios/{edificio}/areas-comunes/{espacio}', [EspacioComunWebController::class, 'show'])->whereUuid('edificio')->whereUuid('espacio')->name('areas-comunes.show');
    Route::get('edificios/{edificio}/areas-comunes/{espacio}/edit', [EspacioComunWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('espacio')->name('areas-comunes.edit');
    Route::put('edificios/{edificio}/areas-comunes/{espacio}', [EspacioComunWebController::class, 'update'])->whereUuid('edificio')->whereUuid('espacio')->name('areas-comunes.update');
    Route::patch('edificios/{edificio}/areas-comunes/{espacio}/estado', [EspacioComunWebController::class, 'changeStatus'])->whereUuid('edificio')->whereUuid('espacio')->name('areas-comunes.status');
    Route::post('edificios/{edificio}/areas-comunes/{espacio}/reglas', [EspacioComunWebController::class, 'storeRule'])->whereUuid('edificio')->whereUuid('espacio')->name('areas-comunes.rules.store');

    Route::get('reservas-areas-comunes', [ReservaAreaComunWebController::class, 'index'])->name('reservas-areas-comunes.index');
    Route::get('reservas-areas-comunes/create', [ReservaAreaComunWebController::class, 'create'])->name('reservas-areas-comunes.create');
    Route::post('edificios/{edificio}/areas-comunes/{espacio}/reservas', [ReservaAreaComunWebController::class, 'store'])->whereUuid('edificio')->whereUuid('espacio')->name('reservas-areas-comunes.store');
    Route::get('edificios/{edificio}/reservas-areas-comunes/{reserva}', [ReservaAreaComunWebController::class, 'show'])->whereUuid('edificio')->whereUuid('reserva')->name('reservas-areas-comunes.show');
    Route::patch('edificios/{edificio}/reservas-areas-comunes/{reserva}/aprobar', [ReservaAreaComunWebController::class, 'approve'])->whereUuid('edificio')->whereUuid('reserva')->name('reservas-areas-comunes.approve');
    Route::patch('edificios/{edificio}/reservas-areas-comunes/{reserva}/rechazar', [ReservaAreaComunWebController::class, 'reject'])->whereUuid('edificio')->whereUuid('reserva')->name('reservas-areas-comunes.reject');
    Route::patch('edificios/{edificio}/reservas-areas-comunes/{reserva}/cancelar', [ReservaAreaComunWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('reserva')->name('reservas-areas-comunes.cancel');
});
