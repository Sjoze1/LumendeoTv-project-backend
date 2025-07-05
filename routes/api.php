<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoController;
use App\Http\Controllers\MpesaController;
use App\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Temporary debug route: Check if CLOUDINARY_API_SECRET is accessible
Route::get('/debug-cloudinary-secret', function () {
    $secret = env('CLOUDINARY_API_SECRET');
    $cloudName = env('CLOUDINARY_CLOUD_NAME');
    $apiKey = env('CLOUDINARY_API_KEY');
    $envApp = env('APP_ENV'); // Also check APP_ENV

    return response()->json([
        'CLOUDINARY_CLOUD_NAME_ENV' => $cloudName,
        'CLOUDINARY_API_KEY_ENV' => $apiKey,
        'CLOUDINARY_API_SECRET_ENV' => $secret,
        'APP_ENV_VAL' => $envApp,
        'secret_type' => gettype($secret),
        'secret_is_null' => is_null($secret),
        'secret_is_empty' => empty($secret)
    ]);
});

// ... your existing routes below ...

Route::get('/videos', [VideoController::class, 'index']);
Route::get('/videos/latest', [VideoController::class, 'latest']);
Route::get('/videos/{id}', [VideoController::class, 'show']);
Route::post('/videos', [VideoController::class, 'store']);
// Route::post('/upload', [VideoController::class, 'store']); // Commented out
Route::put('/videos/{id}', [VideoController::class, 'update']);
Route::delete('/videos/{id}', [VideoController::class, 'destroy']);

// REMOVED: No longer streaming from local storage. Videos are served from Cloudinary CDN.
// Route::get('/videos/stream/{filename}', [VideoController::class, 'stream']);


// --- Mpesa Routes ---
Route::post('/mpesa/stkpush', [MpesaController::class, 'initiateStkPush']);
Route::post('/callback', [CallbackController::class, 'receiveCallback']);
Route::get('/check-callback', [CallbackController::class, 'checkCallback']);
Route::get('/mpesa/payment-status/{checkoutRequestId}', [CallbackController::class, 'checkPaymentStatus']);

// --- General Application Routes ---
Route::get('/', fn() => 'Hello, World!');
Route::get('/about', fn() => 'About');

// --- Admin/Utility Routes ---
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

// Test route for Backblaze B2 connection (useful for debugging your B2 setup)
Route::get('/test-b2', function () {
    $disk = Storage::disk('b2');
    $result = $disk->put('test.txt', 'It works!');
    return $result ? 'Success: File uploaded to B2!' : 'Failed to upload.';
});
