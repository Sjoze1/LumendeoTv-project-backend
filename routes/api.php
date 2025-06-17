<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::post('/upload', [VideoController::class, 'store']);

Route::post('/mpesa/stkpush', [MpesaController::class, 'initiateStkPush']);
Route::post('/mpesa/callback', [MpesaController::class, 'handleCallback'])->withoutMiddleware(['api']);

Route::get('/run-migrations', function (Request $request) {
  // Optional: Add simple security check so only you can run this
  if ($request->header('X-SECRET') !== env('MIGRATION_SECRET')) {
      return response()->json(['message' => 'Unauthorized'], 401);
  }

  Artisan::call('migrate', ['--force' => true]);

  return response()->json(['message' => 'Migrations run successfully']);
});