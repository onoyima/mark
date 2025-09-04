<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_nysc', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_submitted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->string('fname')->nullable();
            $table->string('lname')->nullable();
            $table->string('mname')->nullable();
            $table->enum('gender', ['Male', 'Female'])->nullable();
            $table->date('dob')->nullable();
            $table->enum('marital_status', ['Single', 'Married', 'Divorced', 'Widowed'])->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('state')->nullable();
            $table->string('lga')->nullable();
            $table->string('username')->nullable();
            $table->string('matric_no', 50)->nullable();
            $table->string('department')->nullable();
            $table->string('level', 10)->nullable();
            $table->integer('graduation_year')->nullable();
            $table->decimal('cgpa', 3, 2)->nullable();
            $table->string('jamb_no', 20)->nullable();
            $table->string('study_mode', 100)->nullable();
            $table->timestamps();
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->index(['student_id', 'is_paid', 'is_submitted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_nysc');
    }
};