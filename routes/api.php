<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::post('/upload', [VideoController::class, 'store']);
Route::get('/videos/stream/{filename}', [VideoController::class, 'streamVideo']);

// Initiates STK push
Route::post('/mpesa/stkpush', [MpesaController::class, 'initiateStkPush']);

// Handle M-Pesa callback and update DB
Route::post('/callback', [MpesaController::class, 'handleCallback']);

// Optional test/debug routes (if you still want them)
Route::post('/test-callback', [CallbackController::class, 'receiveCallback']);
Route::get('/check-callback', [CallbackController::class, 'checkCallback']);


Route::get('/', fn() => 'Hello, World!');
Route::get('/about', fn() => 'About');

Route::get('/run-migrations', function (Request $request) {
  if ($request->header('X-SECRET') !== env('MIGRATION_SECRET')) {
      return response()->json(['message' => 'Unauthorized'], 401);
  }

  try {
      Artisan::call('migrate', ['--force' => true]);
      return response()->json([
          'message' => 'Migrations run successfully',
          'output' => Artisan::output()
      ]);
  } catch (\Exception $e) {
      return response()->json([
          'message' => 'Migration failed',
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
      ], 500);
  }
});