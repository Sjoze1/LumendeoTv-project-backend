<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaPayment extends Model
{
    protected $fillable = [
        'phone_number', // Updated to match migration
        'checkout_request_id',
        'merchant_request_id',
        'amount',
        'status',
        'response',
        'paid_at',
        'mpesa_receipt_number', // Added to fillable
        'transaction_date',     // Added to fillable
        'result_code',          // Added to fillable
        'result_desc',          // Added to fillable
        'failure_reason',       // Added to fillable
    ];

    protected $dates = [
        'paid_at',
        'transaction_date', // Added to dates
    ];

    // Optional: Cast amount for automatic decimal handling
    protected $casts = [
        'amount' => 'decimal:2',
    ];
}