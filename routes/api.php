<?php

use App\Banks\BNB\BnbController;
use App\Banks\Union\UnionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| QR API - Multi Banco
|--------------------------------------------------------------------------
| Cada banco tiene su propio prefijo: /api/bnb/..., /api/union/...
| Para agregar un nuevo banco, crear app/Banks/NuevoBanco/ y agregar
| sus rutas aquí con el prefijo correspondiente.
*/

/*
|--------------------------------------------------------------------------
| BNB - Banco Nacional de Bolivia
|--------------------------------------------------------------------------
*/

// Sin API Key — el banco llama a este endpoint directamente al recibir un pago
Route::post('/bnb/qr/notification', [BnbController::class, 'notification']);

// Con API Key — solo sistemas autorizados pueden usar estos endpoints
Route::middleware('api.key')->prefix('bnb/qr')->group(function () {
    Route::post('/generate', [BnbController::class, 'generate']);
    Route::post('/status',   [BnbController::class, 'status']);
    Route::post('/cancel',   [BnbController::class, 'cancel']);
    Route::post('/list',     [BnbController::class, 'list']);
});

/*
|--------------------------------------------------------------------------
| Union - Banco Unión (UNIQR Service — SOAP/XML)
|--------------------------------------------------------------------------
*/

// Sin API Key — el banco llama a estos endpoints directamente
Route::post('/union/qr/notification', [UnionController::class, 'notification']);
Route::get('/union/reporte-qrs/conciliacion', [UnionController::class, 'conciliation']);

// Con API Key — solo sistemas autorizados pueden usar estos endpoints
Route::middleware('api.key')->prefix('union/qr')->group(function () {
    Route::post('/generate', [UnionController::class, 'generate']);
    Route::post('/status',   [UnionController::class, 'status']);
    Route::post('/cancel',   [UnionController::class, 'cancel']);
    Route::post('/list',     [UnionController::class, 'list']);
});
