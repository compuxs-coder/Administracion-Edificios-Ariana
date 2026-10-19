<?php

use Illuminate\Support\Facades\Route;
use Src\Comunicacion\Application\Controllers\ComunicadoWebController;

Route::get('comunicacion/documentos/{documento}/entregas/{entrega}', [ComunicadoWebController::class, 'signedDocument'])
    ->middleware(['throttle:60,1,comunicacion-firma', 'signed'])->whereUuid('documento')->whereUuid('entrega')->name('comunicados.documents.signed');

Route::middleware('auth')->group(function (): void {
    Route::get('comunicados', [ComunicadoWebController::class, 'index'])->name('comunicados.index');
    Route::get('comunicados/create', [ComunicadoWebController::class, 'create'])->name('comunicados.create');
    Route::post('edificios/{edificio}/comunicados', [ComunicadoWebController::class, 'store'])->whereUuid('edificio')->name('comunicados.store');
    Route::get('edificios/{edificio}/comunicados/{comunicado}', [ComunicadoWebController::class, 'show'])->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.show');
    Route::get('edificios/{edificio}/comunicados/{comunicado}/edit', [ComunicadoWebController::class, 'edit'])->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.edit');
    Route::put('edificios/{edificio}/comunicados/{comunicado}', [ComunicadoWebController::class, 'update'])->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.update');
    Route::get('edificios/{edificio}/comunicados/{comunicado}/destinatarios/preview', [ComunicadoWebController::class, 'preview'])->middleware('throttle:30,1,comunicacion-preview')->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.recipients.preview');
    Route::post('edificios/{edificio}/comunicados/{comunicado}/publicar', [ComunicadoWebController::class, 'publish'])->middleware('throttle:5,1,comunicacion-publicar')->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.publish');
    Route::patch('edificios/{edificio}/comunicados/{comunicado}/archivar', [ComunicadoWebController::class, 'archive'])->middleware('throttle:10,1,comunicacion-archivar')->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.archive');
    Route::post('edificios/{edificio}/comunicados/{comunicado}/documentos', [ComunicadoWebController::class, 'storeDocument'])->middleware('throttle:5,1,comunicacion-documentos')->whereUuid('edificio')->whereUuid('comunicado')->name('comunicados.documents.store');
    Route::delete('edificios/{edificio}/comunicados/{comunicado}/documentos/{documento}', [ComunicadoWebController::class, 'deleteDocument'])->whereUuid('edificio')->whereUuid('comunicado')->whereUuid('documento')->name('comunicados.documents.destroy');
    Route::get('edificios/{edificio}/comunicados/{comunicado}/documentos/{documento}/download', [ComunicadoWebController::class, 'downloadDocument'])->middleware('throttle:60,1,comunicacion-descarga')->whereUuid('edificio')->whereUuid('comunicado')->whereUuid('documento')->name('comunicados.documents.download');
    Route::post('edificios/{edificio}/comunicados/{comunicado}/entregas/{entrega}/reintentar', [ComunicadoWebController::class, 'retryDelivery'])->middleware('throttle:20,1,comunicacion-reintento')->whereUuid('edificio')->whereUuid('comunicado')->whereUuid('entrega')->name('comunicados.deliveries.retry');
});
