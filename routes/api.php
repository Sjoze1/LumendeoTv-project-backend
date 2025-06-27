<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
Route::post('/upload', [VideoController::class, 'store']);
Route::get('/videos/stream/{filename}', [VideoController::class, 'streamVideo']);

Route::post('/mpesa/stkpush', [MpesaController::class, 'initiateStkPush']);

Route::post('/callback', [CallbackController::class, 'receiveCallback']);
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

Route::get('/test-b2', function () {
    $disk = Storage::disk('b2');
    $result = $disk->put('test.txt', 'It works!');
    return $result ? 'Success: File uploaded to B2!' : 'Failed to upload.';
});