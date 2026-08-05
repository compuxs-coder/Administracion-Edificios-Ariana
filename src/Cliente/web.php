<?php

use Illuminate\Support\Facades\Route;
use Src\Cliente\Application\Controllers\ClienteWebController;

Route::middleware('auth')->group(function () {
    Route::resource('clientes', ClienteWebController::class);
});
