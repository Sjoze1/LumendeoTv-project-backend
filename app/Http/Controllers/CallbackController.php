<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\MpesaPayment;

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
    
        // Cache the raw callback data for debugging
        $callbacks = Cache::get($this->cacheKey, []);
        $callbacks[] = [
            'data' => $data,
            'timestamp' => now()->timestamp,
            'received_at' => now()->toIso8601String(),
        ];
        Cache::put($this->cacheKey, $callbacks, now()->addHours(1));
    
        try {
            $body = data_get($data, 'Body.stkCallback');
    
            if (empty($body)) {
                Log::warning('Invalid or empty callback data');
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Invalid or empty callback data',
                ]);
            }
    
            $merchantRequestId = $body['MerchantRequestID'] ?? null;
            $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
            $resultCode = $body['ResultCode'] ?? null;
            $resultDesc = $body['ResultDesc'] ?? null;
    
            $callbackMetadata = $body['CallbackMetadata']['Item'] ?? [];
            $metaDataMap = [];
            foreach ($callbackMetadata as $item) {
                if (isset($item['Name'], $item['Value'])) {
                    $metaDataMap[$item['Name']] = $item['Value'];
                }
            }
    
            $payment = MpesaPayment::where('merchant_request_id', $merchantRequestId)->first();
    
            if ($payment) {
                $payment->update([
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'amount' => $metaDataMap['Amount'] ?? $payment->amount,
                    'mpesa_receipt_number' => $metaDataMap['MpesaReceiptNumber'] ?? null,
                    'transaction_date' => isset($metaDataMap['TransactionDate']) 
                        ? Carbon::createFromFormat('YmdHis', $metaDataMap['TransactionDate']) 
                        : null,
                    'phone_number' => $metaDataMap['PhoneNumber'] ?? $payment->phone_number,
                    'response' => json_encode($data),
                ]);
    
                if ($resultCode == 0) {
                    $payment->update([
                        'status' => 'COMPLETED',
                        'paid_at' => now(),
                    ]);
                } else {
                    $payment->update([
                        'status' => 'FAILED',
                        'failure_reason' => $resultDesc,
                    ]);
                }
            } else {
                MpesaPayment::create([
                    'merchant_request_id' => $merchantRequestId,
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'amount' => $metaDataMap['Amount'] ?? null,
                    'mpesa_receipt_number' => $metaDataMap['MpesaReceiptNumber'] ?? null,
                    'transaction_date' => isset($metaDataMap['TransactionDate']) 
                        ? Carbon::createFromFormat('YmdHis', $metaDataMap['TransactionDate']) 
                        : null,
                    'phone_number' => $metaDataMap['PhoneNumber'] ?? null,
                    'status' => $resultCode == 0 ? 'COMPLETED' : 'FAILED',
                    'failure_reason' => $resultCode == 0 ? null : $resultDesc,
                    'paid_at' => $resultCode == 0 ? now() : null,
                    'response' => json_encode($data),
                ]);
            }
    
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'C2B Received Successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Mpesa callback processing error', ['message' => $e->getMessage()]);
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Server error during callback processing',
            ]);
        }
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
