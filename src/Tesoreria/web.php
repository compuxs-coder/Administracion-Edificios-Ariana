<?php

use Illuminate\Support\Facades\Route;
use Src\Tesoreria\Application\Controllers\CuentaTesoreriaWebController;
use Src\Tesoreria\Application\Controllers\MovimientoTesoreriaWebController;

Route::middleware('auth')->group(function (): void {
    Route::get('cuentas-tesoreria', [CuentaTesoreriaWebController::class, 'index'])->name('cuentas-tesoreria.index');
    Route::get('cuentas-tesoreria/create', [CuentaTesoreriaWebController::class, 'create'])->name('cuentas-tesoreria.create');
    Route::post('edificios/{edificio}/cuentas-tesoreria', [CuentaTesoreriaWebController::class, 'store'])->whereUuid('edificio')->name('cuentas-tesoreria.store');
    Route::get('edificios/{edificio}/cuentas-tesoreria/{cuenta}', [CuentaTesoreriaWebController::class, 'show'])->whereUuid('edificio')->whereUuid('cuenta')->name('cuentas-tesoreria.show');
    Route::get('edificios/{edificio}/cuentas-tesoreria/{cuenta}/edit', [CuentaTesoreriaWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('cuenta')->name('cuentas-tesoreria.edit');
    Route::put('edificios/{edificio}/cuentas-tesoreria/{cuenta}', [CuentaTesoreriaWebController::class, 'update'])->whereUuid('edificio')->whereUuid('cuenta')->name('cuentas-tesoreria.update');
    Route::patch('edificios/{edificio}/cuentas-tesoreria/{cuenta}/estado', [CuentaTesoreriaWebController::class, 'changeStatus'])->whereUuid('edificio')->whereUuid('cuenta')->name('cuentas-tesoreria.status');

    Route::get('movimientos-tesoreria', [MovimientoTesoreriaWebController::class, 'index'])->name('movimientos-tesoreria.index');
    Route::get('movimientos-tesoreria/create', [MovimientoTesoreriaWebController::class, 'create'])->name('movimientos-tesoreria.create');
    Route::post('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos', [MovimientoTesoreriaWebController::class, 'store'])->whereUuid('edificio')->whereUuid('cuenta')->name('movimientos-tesoreria.store');
    Route::get('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos/{movimiento}', [MovimientoTesoreriaWebController::class, 'show'])->whereUuid('edificio')->whereUuid('cuenta')->whereUuid('movimiento')->name('movimientos-tesoreria.show');
    Route::patch('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos/{movimiento}/anular', [MovimientoTesoreriaWebController::class, 'cancel'])->whereUuid('edificio')->whereUuid('cuenta')->whereUuid('movimiento')->name('movimientos-tesoreria.cancel');
    Route::get('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos/{movimiento}/conciliar', [MovimientoTesoreriaWebController::class, 'reconcile'])->whereUuid('edificio')->whereUuid('cuenta')->whereUuid('movimiento')->name('movimientos-tesoreria.reconcile');
    Route::post('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos/{movimiento}/conciliaciones', [MovimientoTesoreriaWebController::class, 'storeReconciliation'])->whereUuid('edificio')->whereUuid('cuenta')->whereUuid('movimiento')->name('conciliaciones-tesoreria.store');
    Route::patch('edificios/{edificio}/cuentas-tesoreria/{cuenta}/movimientos/{movimiento}/conciliaciones/{conciliacion}/revertir', [MovimientoTesoreriaWebController::class, 'reverseReconciliation'])->whereUuid('edificio')->whereUuid('cuenta')->whereUuid('movimiento')->whereUuid('conciliacion')->name('conciliaciones-tesoreria.reverse');
});
