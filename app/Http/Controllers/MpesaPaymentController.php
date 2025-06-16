<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MpesaPayment;
use Carbon\Carbon; // Import Carbon for date handling

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
            $body = $request->input('Body.stkCallback');
            
            Log::info('Mpesa callback - Extracted Body.stkCallback:', (array) $body);

            if (empty($body)) {
                Log::warning('Mpesa callback - Body.stkCallback is empty or null.');
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Invalid or empty callback data',
                ]);
            }

            $merchantRequestId = $body['MerchantRequestID'] ?? null;
            $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
            $resultCode = $body['ResultCode'] ?? null;
            $resultDesc = $body['ResultDesc'] ?? null;

            Log::info('Mpesa callback - Extracted main fields:', [
                'MerchantRequestID' => $merchantRequestId,
                'CheckoutRequestID' => $checkoutRequestId,
                'ResultCode' => $resultCode,
                'ResultDesc' => $resultDesc,
            ]);

            $callbackMetadata = $body['CallbackMetadata']['Item'] ?? [];
            Log::info('Mpesa callback - Extracted CallbackMetadata.Item:', (array) $callbackMetadata);

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

            Log::info('Mpesa callback - Fully Parsed Callback Data:', $data);

            // --- START DB SAVING LOGIC ---
            $paymentStatus = 'PENDING'; // Default status
            $failureReason = null;
            $mpesaReceiptNumber = null;
            $transactionDate = null;
            $paidAt = null;

            if ($resultCode == 0) {
                // Successful transaction
                $paymentStatus = 'COMPLETED';
                $mpesaReceiptNumber = $data['MpesaReceiptNumber'] ?? null;
                $transactionDateRaw = $data['TransactionDate'] ?? null;
                // Convert M-Pesa's YYYYMMDDHHmmss string to Carbon instance for timestamp column
                $transactionDate = $transactionDateRaw ? Carbon::createFromFormat('YmdHis', $transactionDateRaw) : null;
                $paidAt = now(); // Set paid_at to current time for successful payments
                Log::info('Mpesa callback - Transaction successful, preparing to save.');
            } else {
                // Transaction failed or was cancelled by the user
                $paymentStatus = 'FAILED';
                $failureReason = $resultDesc; // Use M-Pesa's ResultDesc as failure reason
                Log::warning('Mpesa callback - Transaction failed or cancelled:', [
                    'MerchantRequestID' => $merchantRequestId,
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultDesc,
                ]);
            }

            try {
                MpesaPayment::create([
                    'merchant_request_id' => $merchantRequestId,
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'amount' => $data['Amount'] ?? null,
                    'mpesa_receipt_number' => $mpesaReceiptNumber,
                    'transaction_date' => $transactionDate, // Carbon instance directly for timestamp column
                    'phone_number' => $data['PhoneNumber'] ?? null, // Matches 'phone_number' column
                    'status' => $paymentStatus,
                    'failure_reason' => $failureReason,
                    'paid_at' => $paidAt, // Set only for successful transactions
                    'response' => json_encode($request->all()), // Store the full raw response
                ]);
                Log::info('Mpesa callback - Payment record saved successfully with status: ' . $paymentStatus);
            } catch (\Exception $dbException) {
                Log::error('Mpesa callback - Error saving payment to DB: ' . $dbException->getMessage(), [
                    'file' => $dbException->getFile(),
                    'line' => $dbException->getLine(),
                    'trace' => $dbException->getTraceAsString(),
                    'data_to_save' => [
                        'merchant_request_id' => $merchantRequestId,
                        'checkout_request_id' => $checkoutRequestId,
                        'result_code' => $resultCode,
                        'result_desc' => $resultDesc,
                        'amount' => $data['Amount'] ?? null,
                        'mpesa_receipt_number' => $mpesaReceiptNumber,
                        'transaction_date' => $transactionDate,
                        'phone_number' => $data['PhoneNumber'] ?? null,
                        'status' => $paymentStatus,
                        'failure_reason' => $failureReason,
                        'paid_at' => $paidAt,
                        'response' => json_encode($request->all()),
                    ],
                ]);
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Server error during DB save',
                ]);
            }
            // --- END DB SAVING LOGIC ---

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'C2B Recieved Succesfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Mpesa callback error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_payload' => $request->all(),
            ]);
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Server error during callback processing',
            ]);
        }
    }
}
