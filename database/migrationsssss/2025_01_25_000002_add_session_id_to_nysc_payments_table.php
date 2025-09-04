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
        Schema::table('nysc_payments', function (Blueprint $table) {
            // Add session_id column to link with temporary submissions
            $table->string('session_id')->nullable()->after('student_nysc_id');
            
            // Make student_nysc_id nullable since it will be set after successful payment
            $table->unsignedBigInteger('student_nysc_id')->nullable()->change();
            
            // Add index for session_id
            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nysc_payments', function (Blueprint $table) {
            // Drop the session_id column and its index
            $table->dropIndex(['session_id']);
            $table->dropColumn('session_id');
            
            // Make student_nysc_id required again
            $table->unsignedBigInteger('student_nysc_id')->nullable(false)->change();
        });
    }
};