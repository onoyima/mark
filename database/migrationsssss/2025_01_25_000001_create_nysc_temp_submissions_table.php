<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nysc_temp_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('session_id')->unique(); // To link with payment session
            
            // Personal Information
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
            $table->string('religion', 100)->nullable();
            $table->string('username')->nullable();
            
            // Academic Information
            $table->string('matric_no', 50)->nullable();
            $table->string('course_of_study')->nullable();
            $table->string('department')->nullable();
            $table->string('level', 10)->nullable();
            $table->integer('graduation_year')->nullable();
            $table->decimal('cgpa', 3, 2)->nullable();
            $table->string('jamb_no', 20)->nullable();
            $table->string('study_mode', 100)->nullable();
            
            // Emergency Contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
            $table->string('emergency_contact_relationship', 100)->nullable();
            $table->text('emergency_contact_address')->nullable();
            
            // Medical Information (if needed)
            $table->string('blood_group', 10)->nullable();
            $table->string('genotype', 10)->nullable();
            $table->text('medical_condition')->nullable();
            $table->text('allergies')->nullable();
            $table->text('physical_condition')->nullable();
            
            // Status tracking
            $table->enum('status', ['pending', 'paid', 'expired'])->default('pending');
            $table->timestamp('expires_at')->nullable(); // Auto-cleanup after 24 hours
            $table->timestamps();
            
            // Indexes
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade');
            $table->index(['student_id', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nysc_temp_submissions');
    }
};