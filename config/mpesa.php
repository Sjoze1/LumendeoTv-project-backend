<?php

return [
    'consumer_key' => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'shortcode' => env('MPESA_SHORTCODE'),
    'passkey' => env('MPESA_PASSKEY'),
    'callback_url' => env('MPESA_CALLBACK_URL'),
    'env' => env('MPESA_ENV', 'sandbox'), // Change to 'production' when ready
    'base_url' => env('MPESA_BASE_URL', 'https://sandbox.safaricom.co.ke'), // Default to sandbox URL
];
