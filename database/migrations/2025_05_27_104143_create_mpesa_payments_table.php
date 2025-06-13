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
            $table->string('phone');               // Customer phone number
            $table->string('checkout_request_id')->unique(); // Unique ID from M-Pesa STK Push
            $table->string('merchant_request_id')->nullable(); // Another ID from M-Pesa
            $table->decimal('amount', 10, 2);      // Amount paid
            $table->string('status')->default('pending'); // pending, success, failed
            $table->text('response')->nullable();  // Raw JSON response from M-Pesa
            $table->string('mpesa_receipt_number')->nullable();
            $table->string('transaction_date')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();  // Payment completion timestamp
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('mpesa_payments');
    }
}
