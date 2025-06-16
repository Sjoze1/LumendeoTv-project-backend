<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaController;

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::post('/upload', [VideoController::class, 'store']);

Route::post('/mpesa/stkpush', [MpesaController::class, 'initiateStkPush']);

Route::post('/mpesa/callback', [MpesaController::class, 'handleCallback'])->withoutMiddleware(['api']);

