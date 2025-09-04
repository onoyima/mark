<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nysc_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_nysc_id');
            $table->string('session_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('payment_reference')->unique();
            $table->enum('status', ['pending', 'successful', 'failed'])->default('pending');
            $table->enum('payment_method', ['paystack', 'bank_transfer', 'cash'])->default('paystack');
            $table->json('payment_data')->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('student_nysc_id')->references('id')->on('student_nysc')->onDelete('cascade');
            $table->index(['student_id', 'student_nysc_id', 'status']);
            $table->index('payment_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nysc_payments');
    }
};
