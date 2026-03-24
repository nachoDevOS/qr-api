<?php

use App\Http\Controllers\QrController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QR API - Banco Nacional de Bolivia
|--------------------------------------------------------------------------
| Endpoints para generación y gestión de QRs de cobro.
| Todos los requests deben ser application/json.
*/

Route::prefix('qr')->group(function () {

    // Genera un nuevo QR de cobro
    Route::post('/generate', [QrController::class, 'generate']);

    // Consulta el estado de un QR (1=No Usado, 2=Usado, 3=Expirado, 4=Con Error)
    Route::post('/status', [QrController::class, 'status']);

    // Cancela un QR de uso único no utilizado
    Route::post('/cancel', [QrController::class, 'cancel']);

    // Lista los QRs generados en una fecha determinada
    Route::post('/list', [QrController::class, 'list']);

    // El banco BNB llama a este endpoint cuando se realiza un pago
    Route::post('/notification', [QrController::class, 'notification']);
});
