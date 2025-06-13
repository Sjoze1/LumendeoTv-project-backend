<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaPayment extends Model
{
    protected $fillable = [
        'phone',
        'checkout_request_id',
        'merchant_request_id',
        'amount',
        'status',
        'response',
        'paid_at',
    ];

    protected $dates = ['paid_at'];
}
