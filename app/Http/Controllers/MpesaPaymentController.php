<?php

namespace App\Http\Controllers;

use App\Models\MpesaPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MpesaPaymentController extends Controller
{
    private $consumerKey;
    private $consumerSecret;
    private $shortcode;
    private $passkey;
    private $callbackUrl;

    public function __construct()
    {
        $this->consumerKey = config('mpesa.consumer_key');
        $this->consumerSecret = config('mpesa.consumer_secret');
        $this->shortcode = config('mpesa.shortcode');
        $this->passkey = config('mpesa.passkey');
        $this->callbackUrl = config('mpesa.callback_url'); // This is your Render backend callback URL
    }

    private function getAccessToken()
    {
        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->get('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');

        if ($response->successful()) {
            $body = $response->json();
            return $body['access_token'] ?? null;
        }

        return null;
    }

    private function generatePassword($timestamp)
    {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }

    public function initiatePayment(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'regex:/^2547\d{8}$/'],
        ]);
    
        $phone = $request->input('phone');
        $amount = 1; // static amount, adjust if needed
    
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['success' => false, 'message' => 'Failed to get access token'], 500);
        }
    
        $timestamp = now()->format('YmdHis');
        $password = $this->generatePassword($timestamp);
    
        $payload = [
            "BusinessShortCode" => $this->shortcode,
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => "CustomerPayBillOnline",
            "Amount" => $amount,
            "PartyA" => $phone,
            "PartyB" => $this->shortcode,
            "PhoneNumber" => $phone,
            "CallBackURL" => $this->callbackUrl, // points to Render backend
            "AccountReference" => "YourAppName",
            "TransactionDesc" => "Payment for unlocking full video"
        ];
    
        Log::info('🧪 Callback URL:', ['callback_url' => $this->callbackUrl]);
        Log::info('📦 STK Payload:', $payload);
    
        $response = Http::withToken($accessToken)
            ->post('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest', $payload);
    
        if ($response->successful()) {
            $body = $response->json();
    
            MpesaPayment::create([
                'phone' => $phone,
                'amount' => $amount,
                'status' => 'pending',
                'checkout_request_id' => $body['CheckoutRequestID'] ?? null,
                'response' => json_encode($body)
            ]);
    
            if ($body['ResponseCode'] === '0') {
                return response()->json([
                    'success' => true,
                    'checkoutRequestID' => $body['CheckoutRequestID'],
                    'message' => $body['ResponseDescription']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $body['errorMessage'] ?? 'Payment initiation failed'
                ], 400);
            }
        }
    
        return response()->json(['success' => false, 'message' => 'Error initiating payment'], 500);
    }

    // OPTIONAL: remove or comment out the callback handler here since your callback is handled externally.
    /*
    public function mpesaCallback(Request $request)
    {
        // No longer needed in main app
    }
    */

    // Other CRUD methods remain the same, no change needed
}
