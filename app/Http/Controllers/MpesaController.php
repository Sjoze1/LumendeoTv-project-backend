<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MpesaPayment;
use Carbon\Carbon; // Ensure Carbon is imported for date handling

class MpesaController extends Controller
{
    // Properties to hold M-Pesa configuration details
    protected $consumerKey;
    protected $consumerSecret;
    protected $shortcode;
    protected $passkey;
    protected $callbackUrl;
    protected $baseUrl;

    /**
     * Constructor to initialize M-Pesa configuration from environment/config files.
     */
    public function __construct()
    {
        $this->consumerKey = config('mpesa.consumer_key');
        $this->consumerSecret = config('mpesa.consumer_secret');
        $this->shortcode = config('mpesa.shortcode');
        $this->passkey = config('mpesa.passkey');
        $this->callbackUrl = config('mpesa.callback_url');
        // Default to sandbox if base_url is not explicitly set
        $this->baseUrl = config('mpesa.base_url', 'https://sandbox.safaricom.co.ke');
    }

    /**
     * Fetches the OAuth access token from M-Pesa API.
     * Includes error handling for network issues and API responses.
     *
     * @return string|null The access token on success, or null on failure.
     */
    protected function getAccessToken(): ?string
    {
        $tokenUrl = "{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials";

        Log::info('Requesting OAuth token from M-Pesa');

        try {
            // Set a timeout of 30 seconds for the HTTP request to prevent indefinite hanging.
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->timeout(30)
                ->get($tokenUrl);

            // Check if the HTTP request itself was successful (2xx status code)
            if ($response->successful()) {
                $body = $response->json();
                Log::info('Successfully received OAuth token from M-Pesa.');
                return $body['access_token'] ?? null;
            }

            // Log details if the M-Pesa API returned an error status (e.g., 400, 500)
            Log::error('Failed to get OAuth token - M-Pesa API Response Error:', [
                'status' => $response->status(),
                'response_body' => $response->body(),
                'url' => $tokenUrl
            ]);
            return null;

        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Catches HTTP client-specific errors like connection issues, DNS failures, or explicit timeouts.
            Log::error('Failed to get OAuth token - HTTP Request Exception (Network/Timeout):', [
                'message' => $e->getMessage(),
                'url' => $tokenUrl,
                'trace' => $e->getTraceAsString() // Provide full stack trace for deep debugging
            ]);
            return null;
        } catch (\Exception $e) {
            // Catch any other unexpected exceptions during the token request process
            Log::error('Failed to get OAuth token - General Exception:', [
                'message' => $e->getMessage(),
                'url' => $tokenUrl,
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Initiates an STK Push transaction to the M-Pesa API.
     *
     * @param Request $request Contains 'amount' and 'phone' (and optional 'account_ref', 'desc').
     * @return \Illuminate\Http\JsonResponse Response indicating STK push initiation status.
     */
    public function initiateStkPush(Request $request)
    {
        // Validate incoming request data
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'phone' => ['required', 'regex:/^2547\d{8}$/'], // Validates Kenyan Safaricom mobile numbers
        ]);

        // Get the M-Pesa access token
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return response()->json(['status' => 'error', 'message' => 'Failed to get access token'], 500);
        }

        // Generate timestamp and password for M-Pesa authentication
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        // Prepare the STK Push payload as per M-Pesa API requirements
        $payload = [
            "BusinessShortCode" => $this->shortcode,
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => "CustomerPayBillOnline", // Or "CustomerBuyGoodsOnline" for Till Numbers
            "Amount" => (int) $request->amount, // Amount must be an integer
            "PartyA" => $request->phone, // Customer's phone number
            "PartyB" => $this->shortcode, // Your business shortcode
            "PhoneNumber" => $request->phone, // Customer's phone number again
            "CallBackURL" => $this->callbackUrl, // Your public callback URL
            "AccountReference" => $request->input('account_ref', 'PAYMENT_' . time()), // Unique account reference
            "TransactionDesc" => $request->input('desc', 'Payment'), // Transaction description
        ];

        $stkPushUrl = "{$this->baseUrl}/mpesa/stkpush/v1/processrequest";

        Log::info('Initiating STK Push with payload:', $payload);

        try {
            // Send the STK Push request to M-Pesa API
            $stkResponse = Http::withToken($accessToken)
                ->timeout(30) // Set timeout for STK Push request
                ->post($stkPushUrl, $payload);

            // Check if the STK Push API call was successful (not just HTTP OK, but M-Pesa's internal response)
            if (!$stkResponse->ok()) {
                Log::error('STK Push failed - M-Pesa API Response Error:', [
                    'response' => $stkResponse->json(),
                    'status' => $stkResponse->status(),
                    'url' => $stkPushUrl,
                    'payload' => $payload
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'STK Push initiation failed',
                    'details' => $stkResponse->json(),
                ], 500);
            }

            // Log successful STK Push initiation response from M-Pesa
            Log::info('STK Push initiated successfully:', ['response' => $stkResponse->json()]);

            return response()->json([
                'status' => 'success',
                'message' => 'STK Push initiated successfully',
                'data' => $stkResponse->json(),
            ]);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Catch network/timeout errors during STK Push request
            Log::error('STK Push failed - HTTP Request Exception (Network/Timeout):', [
                'message' => $e->getMessage(),
                'url' => $stkPushUrl,
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'STK Push network or timeout error',
                'details' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            // Catch any other general exceptions during STK Push
            Log::error('STK Push failed - General Exception:', [
                'message' => $e->getMessage(),
                'url' => $stkPushUrl,
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'STK Push general error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handles the M-Pesa STK Push callback notification.
     * This method is called by M-Pesa's servers after a transaction completes (success or failure).
     *
     * @param Request $request The incoming callback request from M-Pesa.
     * @return \Illuminate\Http\JsonResponse A response expected by M-Pesa (ResultCode 0 for success).
     */
    public function handleCallback(Request $request)
    {
        // Log the full raw request body for debugging
        Log::info('Mpesa callback received - Raw Request Body:', $request->all());

        try {
            // Extract the 'stkCallback' part from the 'Body'
            $body = $request->input('Body.stkCallback');
            
            Log::info('Mpesa callback - Extracted Body.stkCallback:', (array) $body);

            // Validate if the essential callback data exists
            if (empty($body)) {
                Log::warning('Mpesa callback - Body.stkCallback is empty or null, returning invalid data response.');
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Invalid or empty callback data',
                ]);
            }

            // Extract key fields from the callback
            $merchantRequestId = $body['MerchantRequestID'] ?? null;
            $checkoutRequestId = $body['CheckoutRequestID'] ?? null;
            $resultCode = $body['ResultCode'] ?? null;
            $resultDesc = $body['ResultDesc'] ?? null; // Description of the transaction outcome

            Log::info('Mpesa callback - Extracted main fields:', [
                'MerchantRequestID' => $merchantRequestId,
                'CheckoutRequestID' => $checkoutRequestId,
                'ResultCode' => $resultCode,
                'ResultDesc' => $resultDesc,
            ]);

            // Extract items from CallbackMetadata, if available
            $callbackMetadata = $body['CallbackMetadata']['Item'] ?? [];
            Log::info('Mpesa callback - Extracted CallbackMetadata.Item:', (array) $callbackMetadata);

            // Transform CallbackMetadata into an associative array for easier access
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
                Log::warning('Mpesa callback - CallbackMetadata.Item is empty or not an array for CheckoutRequestID: ' . $checkoutRequestId, (array) $callbackMetadata);
            }

            Log::info('Mpesa callback - Fully Parsed Callback Data:', $data);

            // --- Database Saving Logic ---
            $paymentStatus = 'PENDING'; // Default status before processing
            $failureReason = null;
            $mpesaReceiptNumber = null;
            $transactionDate = null;
            $paidAt = null;

            if ($resultCode == 0) {
                // Transaction was successful
                $paymentStatus = 'COMPLETED';
                $mpesaReceiptNumber = $data['MpesaReceiptNumber'] ?? null;
                $transactionDateRaw = $data['TransactionDate'] ?? null;
                // Convert M-Pesa's YYYYMMDDHHmmss string to a Carbon instance for database timestamp column
                $transactionDate = $transactionDateRaw ? Carbon::createFromFormat('YmdHis', $transactionDateRaw) : null;
                $paidAt = now(); // Record the time the callback was received (payment completed)
                Log::info('Mpesa callback - Transaction successful, preparing to save to DB.');
            } else {
                // Transaction failed or was cancelled by the user/system
                $paymentStatus = 'FAILED';
                $failureReason = $resultDesc; // Use M-Pesa's ResultDesc to explain the failure
                Log::warning('Mpesa callback - Transaction failed or cancelled for CheckoutRequestID: ' . $checkoutRequestId, [
                    'ResultCode' => $resultCode,
                    'ResultDesc' => $resultDesc,
                ]);
            }

            try {
                // Create a new record in the mpesa_payments table
                MpesaPayment::create([
                    'merchant_request_id' => $merchantRequestId,
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'amount' => $data['Amount'] ?? null,
                    'mpesa_receipt_number' => $mpesaReceiptNumber,
                    'transaction_date' => $transactionDate, // Carbon instance directly
                    'phone_number' => $data['PhoneNumber'] ?? null, // Ensure this matches your migration's column name
                    'status' => $paymentStatus,
                    'failure_reason' => $failureReason,
                    'paid_at' => $paidAt,
                    'response' => json_encode($request->all()), // Store the full raw JSON callback for auditing
                ]);
                Log::info('Mpesa callback - Payment record saved successfully with status: ' . $paymentStatus . ' for CheckoutRequestID: ' . $checkoutRequestId);
            } catch (\Exception $dbException) {
                // Catch and log specific errors during the database save operation
                Log::error('Mpesa callback - Error saving payment to DB for CheckoutRequestID: ' . $checkoutRequestId . ': ' . $dbException->getMessage(), [
                    'file' => $dbException->getFile(),
                    'line' => $dbException->getLine(),
                    'trace' => $dbException->getTraceAsString(),
                    'data_to_save' => [ // Log the data you attempted to save for debugging
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
                // Return an error to M-Pesa if DB saving failed, as this is a critical step
                return response()->json([
                    'ResultCode' => 1,
                    'ResultDesc' => 'Server error during DB save',
                ]);
            }
            // --- End Database Saving Logic ---

            // Return success response to M-Pesa to acknowledge receipt of callback
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'C2B Recieved Succesfully',
            ]);
        } catch (\Exception $e) {
            // Catch any unexpected general errors during the entire callback processing
            Log::error('Mpesa callback processing general error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_payload' => $request->all(), // Log the full payload that caused the error
            ]);
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Server error during callback processing',
            ]);
        }
    }
}
