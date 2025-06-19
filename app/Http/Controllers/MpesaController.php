<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\MpesaPayment;
use Carbon\Carbon;

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

    protected function getAccessToken(): ?string
    {
        $tokenUrl = "{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials";

        try {
            $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
                ->timeout(30)
                ->get($tokenUrl);

            if ($response->successful()) {
                return $response->json()['access_token'] ?? null;
            }

            Log::error('Failed to get OAuth token', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('OAuth token request error', ['message' => $e->getMessage()]);
            return null;
        }
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

        $accountRef = $request->input('account_ref', 'PAYMENT_' . time());
        $transactionDesc = $request->input('desc', 'Payment');

        $payload = [
            "BusinessShortCode" => $this->shortcode,
            "Password" => $password,
            "Timestamp" => $timestamp,
            "TransactionType" => "CustomerPayBillOnline",
            "Amount" => (int) $request->amount,
            "PartyA" => $request->phone,
            "PartyB" => $this->shortcode,
            "PhoneNumber" => $request->phone,
            "CallBackURL" => "https://lumendeotv-project-backend.onrender.com/api/callback",
            "AccountReference" => $accountRef,
            "TransactionDesc" => $transactionDesc,
        ];

        $stkPushUrl = "{$this->baseUrl}/mpesa/stkpush/v1/processrequest";

        try {
            $stkResponse = Http::withToken($accessToken)
                ->timeout(30)
                ->post($stkPushUrl, $payload);

            if (!$stkResponse->ok()) {
                Log::error('STK Push failed', [
                    'response' => $stkResponse->json(),
                    'status' => $stkResponse->status(),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'STK Push initiation failed',
                    'details' => $stkResponse->json(),
                ], 500);
            }

            $responseData = $stkResponse->json();

            MpesaPayment::create([
                'merchant_request_id' => $responseData['MerchantRequestID'] ?? null,
                'checkout_request_id' => $responseData['CheckoutRequestID'] ?? null,
                'amount' => $request->amount,
                'phone_number' => $request->phone,
                'account_reference' => $accountRef,
                'transaction_desc' => $transactionDesc,
                'status' => 'PENDING',
                'response' => json_encode($responseData),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'STK Push initiated successfully',
                'data' => $responseData,
            ]);
        } catch (\Exception $e) {
            Log::error('STK Push request error', ['message' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'STK Push error',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function handleCallback(Request $request)
    {
        Log::info('Mpesa callback received', $request->all());

        try {
            $body = $request->input('Body.stkCallback');
            if (empty($body)) {
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
            $data = [];
            if (is_array($callbackMetadata)) {
                foreach ($callbackMetadata as $item) {
                    if (isset($item['Name']) && isset($item['Value'])) {
                        $data[$item['Name']] = $item['Value'];
                    }
                }
            }

            $payment = MpesaPayment::where('merchant_request_id', $merchantRequestId)->first();

            if ($payment) {
                $payment->update([
                    'checkout_request_id' => $checkoutRequestId,
                    'result_code' => $resultCode,
                    'result_desc' => $resultDesc,
                    'amount' => $data['Amount'] ?? $payment->amount,
                    'mpesa_receipt_number' => $data['MpesaReceiptNumber'] ?? null,
                    'transaction_date' => isset($data['TransactionDate']) ? Carbon::createFromFormat('YmdHis', $data['TransactionDate']) : null,
                    'phone_number' => $data['PhoneNumber'] ?? $payment->phone_number,
                    'response' => json_encode($request->all()),
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
                    'amount' => $data['Amount'] ?? null,
                    'mpesa_receipt_number' => $data['MpesaReceiptNumber'] ?? null,
                    'transaction_date' => isset($data['TransactionDate']) ? Carbon::createFromFormat('YmdHis', $data['TransactionDate']) : null,
                    'phone_number' => $data['PhoneNumber'] ?? null,
                    'status' => $resultCode == 0 ? 'COMPLETED' : 'FAILED',
                    'failure_reason' => $resultCode == 0 ? null : $resultDesc,
                    'paid_at' => $resultCode == 0 ? now() : null,
                    'response' => json_encode($request->all()),
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
}
