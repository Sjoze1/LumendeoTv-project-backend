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
        $this->callbackUrl = config('mpesa.callback_url');
    }

    // Generate access token from Safaricom
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

    // Generate password for STK Push
    private function generatePassword($timestamp)
    {
        return base64_encode($this->shortcode . $this->passkey . $timestamp);
    }

    // Initiate M-Pesa STK Push
    public function initiatePayment(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'regex:/^2547\d{8}$/'],
        ]);
    
        $phone = $request->input('phone');
        $amount = 1; // static for now
    
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
            "CallBackURL" => $this->callbackUrl,
            "AccountReference" => "YourAppName",
            "TransactionDesc" => "Payment for unlocking full video"
        ];
    
        // ✅ Log the callback URL and full payload
        Log::info('🧪 Callback URL being sent:', ['callback_url' => $this->callbackUrl]);
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

    // Callback handler from Safaricom
    public function mpesaCallback(Request $request)
    {
        // Log raw callback data
        Log::info('M-Pesa Callback Received:', $request->all());
    
        $body = $request->all();
    
        $resultCode = $body['Body']['stkCallback']['ResultCode'] ?? null;
        $checkoutRequestID = $body['Body']['stkCallback']['CheckoutRequestID'] ?? null;
    
        Log::info("Callback - ResultCode: $resultCode, CheckoutRequestID: $checkoutRequestID");
    
        // Find payment by checkoutRequestID
        $payment = MpesaPayment::where('checkout_request_id', $checkoutRequestID)->first();
    
        if (!$payment) {
            Log::warning("Payment record not found for CheckoutRequestID: $checkoutRequestID");
            // Respond to M-Pesa server anyway to acknowledge receipt
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }
    
        // Update payment status based on ResultCode with loose comparison
        if ($resultCode == 0) {
            $payment->status = 'success';
    
            $callbackMetadata = $body['Body']['stkCallback']['CallbackMetadata']['Item'] ?? [];
            $metadata = [];
            foreach ($callbackMetadata as $item) {
                $metadata[$item['Name']] = $item['Value'];
            }
    
            $payment->amount = $metadata['Amount'] ?? $payment->amount;
            $payment->mpesa_receipt_number = $metadata['MpesaReceiptNumber'] ?? null;
            $payment->transaction_date = $metadata['TransactionDate'] ?? null;
            $payment->phone = $metadata['PhoneNumber'] ?? $payment->phone;
    
            Log::info("Payment marked SUCCESS for CheckoutRequestID: $checkoutRequestID", $metadata);
        } else {
            $payment->status = 'failed';
            $payment->failure_reason = $body['Body']['stkCallback']['ResultDesc'] ?? 'Unknown failure';
    
            Log::info("Payment marked FAILED for CheckoutRequestID: $checkoutRequestID, Reason: " . $payment->failure_reason);
        }
    
        $payment->save();
    
        // Respond to M-Pesa server to confirm receipt
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }    

    // Store manually a payment (for admin/API use)
    public function store(Request $request)
    {
        $request->validate([
            'phone' => 'required|regex:/^2547\d{8}$/',
            'amount' => 'required|numeric|min:1',
        ]);

        $payment = MpesaPayment::create([
            'phone' => $request->phone,
            'amount' => $request->amount,
            'status' => 'pending',
        ]);

        return response()->json($payment, 201);
    }

    public function index()
    {
        return response()->json(MpesaPayment::all());
    }

    public function show($id)
    {
        $payment = MpesaPayment::find($id);
        if (!$payment) return response()->json(['error' => 'Not found'], 404);
        return response()->json($payment);
    }

    public function update(Request $request, $id)
    {
        $payment = MpesaPayment::find($id);
        if (!$payment) return response()->json(['error' => 'Not found'], 404);

        $payment->update($request->only(['status', 'amount']));

        return response()->json($payment);
    }

    public function destroy($id)
    {
        $payment = MpesaPayment::find($id);
        if (!$payment) return response()->json(['error' => 'Not found'], 404);

        $payment->delete();
        return response()->json(['message' => 'Deleted successfully']);
    }
}
