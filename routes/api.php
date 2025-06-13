<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaPaymentController;

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::post('/upload', [VideoController::class, 'store']);

Route::post('/pay', [MpesaPaymentController::class, 'initiatePayment']);
Route::post('/mpesa/callback', [MpesaPaymentController::class, 'mpesaCallback']);

Route::prefix('payments')->group(function () {
    Route::get('/', [MpesaPaymentController::class, 'index']);          // List all payments
    Route::post('/', [MpesaPaymentController::class, 'store']);         // Store a payment manually
    Route::get('{id}', [MpesaPaymentController::class, 'show']);        // Show specific payment
    Route::put('{id}', [MpesaPaymentController::class, 'update']);      // Update a payment
    Route::delete('{id}', [MpesaPaymentController::class, 'destroy']);  // Delete a payment
});