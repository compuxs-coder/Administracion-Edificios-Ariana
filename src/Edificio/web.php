<?php

use Illuminate\Support\Facades\Route;
use Src\Edificio\Application\Controllers\EdificioWebController;

Route::middleware('auth')->group(function () {
    Route::patch('edificios/{edificio}/estado', [EdificioWebController::class, 'changeStatus'])
        ->name('edificios.estado');
    Route::resource('edificios', EdificioWebController::class)
        ->except('destroy');
});
