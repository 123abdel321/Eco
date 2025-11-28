<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

//CONTROLLERS
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\WhatsappController;
use App\Http\Controllers\Api\CredencialController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

//WEBHOOKS
Route::controller(WhatsappController::class)->group(function () {
    Route::post('whatsapp/webhook', 'webHook');
});
//AUTH 
Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
    Route::post('register', 'register');
});

//WITH TOKEN
Route::middleware('auth:sanctum')->group(function () {
    //AUTH 
    Route::controller(AuthController::class)->group(function () {
        Route::get('logout', 'logout');
    });
    //WHATSAPP
    Route::controller(WhatsappController::class)->group(function () {
        Route::get('whatsapp/list', 'list');
        Route::post('whatsapp/send', 'send');
    });
    // EMAIL
    Route::controller(EmailController::class)->group(function () {
        Route::post('email/send', 'send');
    });
    //CREDENCIALES
    Route::controller(CredencialController::class)->prefix('credenciales')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{id}', 'update');
        Route::delete('/{id}', 'destroy');
        Route::post('/{id}/predeterminada', 'setPredeterminada');
        Route::post('/{id}/verificar', 'verificar');
    });
});
