<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    protected $consumerKey;
    protected $consumerSecret;
    protected $shortcode;
    protected $passkey;
    protected $callbackUrl;
    protected $baseUrl;

    public function __construct()
    {
        $this->consumerKey = config('mpesa.consumer_key');
        $this->consumerSecret = config('mpesa.consumer_secret');
        $this->shortcode = config('mpesa.shortcode');
        $this->passkey = config('mpesa.passkey');
        $this->callbackUrl = config('mpesa.callback_url');
        $this->baseUrl = config('mpesa.base_url', 'https://sandbox.safaricom.co.ke');
    }

    /**
     * Get OAuth access token from M-Pesa API
     */
    protected function getAccessToken()
    {
        $tokenUrl = "{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials";

        Log::info('Requesting OAuth token from M-Pesa', ['url' => $tokenUrl]);

        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->get($tokenUrl);

        if ($response->successful()) {
            $body = $response->json();
            Log::info('Received OAuth token', ['token' => substr($body['access_token'], 0, 10) . '...']);
            return $body['access_token'] ?? null;
        }

        Log::error('Failed to get OAuth token', ['response' => $response->body()]);
        return null;
    }

    public function initiateStkPush(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone' => ['required', 'regex:/^2547\d{8}$/'], // Accepts 2547XXXXXXXX only
        ]);

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['status' => 'error', 'message' => 'Failed to get access token'], 500);
        }

        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            "BusinessShortCode" => $this->shortcode,
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => "CustomerPayBillOnline",
            "Amount" => (int) $request->amount,
            "PartyA" => $request->phone,
            "PartyB" => $this->shortcode,
            "PhoneNumber" => $request->phone,
            "CallBackURL" => $this->callbackUrl,
            "AccountReference" => $request->input('account_ref', 'PAYMENT_' . time()),
            "TransactionDesc" => $request->input('desc', 'Payment'),
        ];

        Log::info('Sending STK Push request', ['payload' => $payload]);

        $stkPushUrl = "{$this->baseUrl}/mpesa/stkpush/v1/processrequest";

        $stkResponse = Http::withToken($accessToken)
            ->post($stkPushUrl, $payload);

        if (!$stkResponse->ok()) {
            Log::error('STK Push failed', ['response' => $stkResponse->json()]);
            return response()->json([
                'status' => 'error',
                'message' => 'STK Push initiation failed',
                'details' => $stkResponse->json(),
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'STK Push initiated successfully',
            'data' => $stkResponse->json(),
        ]);
    }

    public function handleCallback(Request $request)
    {
        $data = $request->all();
        Log::info('M-Pesa Callback Received:', $data);
    
        $callback = data_get($data, 'Body.stkCallback');
    
        if (is_null($callback)) {
            Log::warning('Invalid M-Pesa callback structure:', $data);
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback structure'], 400);
        }
    
        if ($callback['ResultCode'] === 0) {
            Log::info("Successful transaction: Receipt {$callback['MpesaReceiptNumber']}, Amount {$callback['Amount']}, Phone {$callback['PhoneNumber']}, CheckoutRequestID {$callback['CheckoutRequestID']}");
            // Save transaction or update order status here
        } else {
            $resultCode = $callback['ResultCode'];
            $desc = $callback['ResultDesc'];
            Log::warning("Failed transaction: $resultCode - $desc");
            // Optional: Save failed transaction info
        }
    
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback received and processed.']);
    }    
}
