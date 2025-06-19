<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class CallbackController extends Controller
{
    protected $cacheKey = 'callback_data';
    protected $maxAge = 3600; // 1 hour in seconds

    public function receiveCallback(Request $request)
    {
        $data = $request->json()->all();
    
        if (empty($data)) {
            Log::warning('Callback received with empty JSON data');
            return response()->json(['error' => 'No JSON data received'], 400);
        }
    
        Log::info('Callback received', ['callback_data' => $data]);
    
        $callbacks = Cache::get($this->cacheKey, []);
    
        $callbacks[] = [
            'data' => $data,
            'timestamp' => now()->timestamp,
            'received_at' => now()->toIso8601String(),
        ];
    
        Cache::put($this->cacheKey, $callbacks, now()->addHours(1));
    
        return response()->json([
            'status' => 'success',
            'message' => 'Callback received',
        ]);
    }    

    public function checkCallback()
    {
        $now = now()->timestamp;
        $callbacks = Cache::get($this->cacheKey, []);

        // Filter out old entries
        $filtered = array_filter($callbacks, function ($item) use ($now) {
            return ($now - $item['timestamp']) < $this->maxAge;
        });

        // Update cache with cleaned data
        Cache::put($this->cacheKey, array_values($filtered), now()->addHours(1));

        return response()->json([
            'status' => 'success',
            'count' => count($filtered),
            'callbacks' => array_map(function ($item) {
                return [
                    'data' => $item['data'],
                    'received_at' => $item['received_at'],
                ];
            }, $filtered)
        ]);
    }
}
