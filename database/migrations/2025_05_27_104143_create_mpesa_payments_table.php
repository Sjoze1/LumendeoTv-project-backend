<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMpesaPaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number'); // Changed from 'phone' to 'phone_number' for consistency
            $table->string('checkout_request_id')->unique();
            $table->string('merchant_request_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, success, failed
            $table->text('response')->nullable(); // Raw JSON response from M-Pesa
            $table->string('mpesa_receipt_number')->nullable();
            $table->timestamp('transaction_date')->nullable(); // Changed from string to timestamp
            $table->string('result_code')->nullable(); // Added: M-Pesa ResultCode
            $table->string('result_desc')->nullable(); // Added: M-Pesa ResultDesc
            $table->string('failure_reason')->nullable(); // Can store the ResultDesc if transaction fails
            $table->timestamp('paid_at')->nullable(); // Payment completion timestamp
            $table->timestamps(); // created_at, updated_at
        });
    }

    public function down()
    {
        Schema::dropIfExists('mpesa_payments');
    }
}