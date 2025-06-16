<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MpesaPayment;

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

    protected function getAccessToken()
    {
        $tokenUrl = "{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials";

        Log::info('Requesting OAuth token from M-Pesa');

        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->get($tokenUrl);

        if ($response->successful()) {
            $body = $response->json();
            return $body['access_token'] ?? null;
        }

        Log::error('Failed to get OAuth token', ['response' => $response->body()]);
        return null;
    }

    public function initiateStkPush(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone' => ['required', 'regex:/^2547\d{8}$/'], 
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
    Log::info('Mpesa callback received:', $request->all());

    try {
        $data = $request->input('Body.stkCallback');
        if (!$data) {
            Log::warning('No stkCallback data in request.');
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
        }

        // Convert M-Pesa transaction date string to timestamp
        $paidAt = null;
        if (!empty($data['TransactionDate'])) {
            $paidAt = \DateTime::createFromFormat('YmdHis', $data['TransactionDate']);
            if ($paidAt) {
                $paidAt = $paidAt->format('Y-m-d H:i:s');
            } else {
                $paidAt = null;
            }
        }

        MpesaPayment::updateOrCreate(
            ['checkout_request_id' => $data['CheckoutRequestID']],
            [
                'phone' => $data['PhoneNumber'] ?? null,
                'merchant_request_id' => $data['MerchantRequestID'] ?? null,
                'amount' => $data['Amount'] ?? 0,
                'status' => $data['ResultCode'] === 0 ? 'success' : 'failed',
                'response' => json_encode($request->all()),
                'mpesa_receipt_number' => $data['MpesaReceiptNumber'] ?? null,
                'transaction_date' => $data['TransactionDate'] ?? null,
                'failure_reason' => $data['ResultDesc'] ?? null,
                'paid_at' => $paidAt,
            ]
        );

        Log::info('Mpesa payment saved/updated.');

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    } catch (\Throwable $e) {
        Log::error('Callback handler error:', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request' => $request->all(),
        ]);
        return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Server error']);
    }
}

}