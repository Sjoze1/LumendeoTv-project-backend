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
        Log::info('Mpesa callback received - Raw Request Body:', $request->all());

        try {
            // Attempt to get the stkCallback data from the 'Body' key
            $body = $request->input('Body.stkCallback');
            
            Log::info('Mpesa callback - Extracted Body.stkCallback:', (array) $body);

            if (empty($body)) { // Using empty to check for null or empty array
                Log::warning('Mpesa callback - Body.stkCallback is empty or null.');
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Invalid or empty callback data',
                ]);
            }

            // Extract necessary fields
            // Use null coalescing operator defensively for all accesses
            $merchantRequestId = $body['MerchantRequestID'] ?? null;
            $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
            $resultCode = $body['ResultCode'] ?? null;
            $resultDesc = $body['ResultDesc'] ?? null; // Added resultDesc

            Log::info('Mpesa callback - Extracted main fields:', [
                'MerchantRequestID' => $merchantRequestId,
                'CheckoutRequestID' => $checkoutRequestId,
                'ResultCode' => $resultCode,
                'ResultDesc' => $resultDesc,
            ]);

            $callbackMetadata = $body['CallbackMetadata']['Item'] ?? [];
            Log::info('Mpesa callback - Extracted CallbackMetadata.Item:', (array) $callbackMetadata);

            // Extract values from CallbackMetadata
            $data = [];
            if (!empty($callbackMetadata) && is_array($callbackMetadata)) {
                foreach ($callbackMetadata as $item) {
                    if (isset($item['Name']) && isset($item['Value'])) {
                        $data[$item['Name']] = $item['Value'];
                    } else {
                        Log::warning('Mpesa callback - CallbackMetadata item missing Name or Value:', (array) $item);
                    }
                }
            } else {
                Log::warning('Mpesa callback - CallbackMetadata.Item is empty or not an array.', (array) $callbackMetadata);
            }

            // You can log this for debugging
            Log::info('Mpesa callback - Fully Parsed Callback Data:', $data);

            // TODO: Save or process payment here...
            // Example: If you were to save it, you might do something like:
            // if ($resultCode == 0) {
            //     MpesaPayment::create([
            //         'merchant_request_id' => $merchantRequestId,
            //         'checkout_request_id' => $checkoutRequestId,
            //         'amount' => $data['Amount'] ?? null,
            //         'mpesa_receipt_number' => $data['MpesaReceiptNumber'] ?? null,
            //         'transaction_date' => $data['TransactionDate'] ?? null,
            //         'phone_number' => $data['PhoneNumber'] ?? null,
            //         'result_code' => $resultCode,
            //         'result_desc' => $resultDesc,
            //         'status' => 'COMPLETED',
            //     ]);
            //     Log::info('Mpesa callback - Payment record created successfully.');
            // } else {
            //     // Handle failed transaction, e.g., update a pending record
            //     Log::warning('Mpesa callback - Transaction failed or cancelled:', ['resultCode' => $resultCode, 'resultDesc' => $resultDesc]);
            // }


            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'C2B Recieved Succesfully', // More descriptive success message for M-Pesa
            ]);
        } catch (\Exception $e) {
            Log::error('Mpesa callback error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_payload' => $request->all(), // Log the full request payload on error
            ]);
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Server error during callback processing', // More specific error message
            ]);
        }
    }
}

