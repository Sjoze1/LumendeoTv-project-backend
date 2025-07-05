<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\MpesaPayment;
use Illuminate\Support\Facades\DB;

class CallbackController extends Controller
{
    protected $cacheKey = 'callback_data';
    protected $maxAge = 3600; // 1 hour in seconds

    public function receiveCallback(Request $request)
    {
        // Step 1: Immediately acknowledge Safaricom (must be fast)
        response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback received successfully'])->send();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // Only works on PHP-FPM (e.g., in production)
        }
    
        // Step 2: Continue processing in background (logging, DB, cache)
        try {
            $data = $request->json()->all();
    
            if (empty($data)) {
                Log::warning('Callback received with empty JSON data');
                return;
            }
    
            Log::info('Callback received', ['callback_data' => $data]);
    
            // Save to cache
            $callbacks = Cache::get($this->cacheKey, []);
            $callbacks[] = [
                'data' => $data,
                'timestamp' => now()->timestamp,
                'received_at' => now()->toIso8601String(),
            ];
            Cache::put($this->cacheKey, $callbacks, now()->addHours(1));
    
            // --- DB Update ---
            $body = $data['Body']['stkCallback'] ?? null;
            if ($body) {
                $merchantRequestId = $body['MerchantRequestID'] ?? null;
                $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
                $resultCode = $body['ResultCode'] ?? null;
                $resultDesc = $body['ResultDesc'] ?? null;
    
                $callbackMetadata = $body['CallbackMetadata']['Item'] ?? [];
                $metaDataArr = [];
                foreach ($callbackMetadata as $item) {
                    if (isset($item['Name'], $item['Value'])) {
                        $metaDataArr[$item['Name']] = $item['Value'];
                    }
                }
    
                $payment = MpesaPayment::firstOrNew(['merchant_request_id' => $merchantRequestId]);
    
                $payment->checkout_request_id = $checkoutRequestId;
                $payment->result_code = $resultCode;
                $payment->result_desc = $resultDesc;
                $payment->amount = $metaDataArr['Amount'] ?? $payment->amount;
                $payment->mpesa_receipt_number = $metaDataArr['MpesaReceiptNumber'] ?? $payment->mpesa_receipt_number;
                $payment->transaction_date = isset($metaDataArr['TransactionDate'])
                    ? Carbon::createFromFormat('YmdHis', $metaDataArr['TransactionDate'])
                    : $payment->transaction_date;
                $payment->phone_number = $metaDataArr['PhoneNumber'] ?? $payment->phone_number;
                $payment->response = json_encode($data);
                $payment->status = ($resultCode == 0) ? 'COMPLETED' : 'FAILED';
                $payment->failure_reason = ($resultCode == 0) ? null : $resultDesc;
                if ($resultCode == 0) {
                    $payment->paid_at = now();
                }
                $payment->save();
            }
        } catch (\Throwable $e) {
            Log::error('Error while handling callback', ['error' => $e->getMessage()]);
        }
    }

    public function checkPaymentStatus($checkoutRequestId)
{
    $payment = MpesaPayment::where('checkout_request_id', $checkoutRequestId)->first();

    if (!$payment) {
        return response()->json([
            'status' => 'not_found',
            'message' => 'No payment found',
        ], 404);
    }

    return response()->json([
        'status' => strtolower($payment->status),
        'paid_at' => $payment->paid_at,
        'receipt' => $payment->mpesa_receipt_number,
        'phone' => $payment->phone_number,
        'amount' => $payment->amount,
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
