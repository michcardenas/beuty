<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Sincronización de catálogo Miracle -> Belleza Áurea (solo lectura)
|--------------------------------------------------------------------------
| Endpoint autenticado por token (?token= o header X-Sync-Token /
| Authorization: Bearer). Expone productos con precios Centro de
| Experiencia y Distribuidor, stock, imágenes y variantes.
*/
Route::get('/v1/aurea/productos', [\App\Http\Controllers\Api\ProductoSyncController::class, 'productos'])
    ->name('api.aurea.productos');
