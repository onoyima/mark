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
        Schema::table('student_nysc', function (Blueprint $table) {
            // Remove payment-related fields (moved to nysc_payments table)
            $table->dropColumn([
                'payment_amount',
                'payment_reference', 
                'payment_date'
            ]);
            
            // Remove fields not in the new specification
            $table->dropColumn([
                'title',
                'city',
                'religion',
                'state_of_origin',
                'course_of_study',
                'department_id',
                'study_mode_id',
                'emergency_contact_name',
                'emergency_contact_phone',
                'emergency_contact_relationship',
                'emergency_contact_address'
            ]);
            
            // Add new fields according to specification
            $table->string('state')->nullable()->after('address');
            $table->string('username')->nullable()->after('lga');
            $table->string('jamb_no', 20)->nullable()->after('cgpa');
            $table->integer('update-count')->default(0)->after('study_mode');
            
            // Remove faculty field
            $table->dropColumn('faculty');
            
            // Modify existing fields to match specification
            $table->string('mname')->nullable()->change();
            $table->string('lga')->nullable()->change();
            $table->decimal('cgpa', 3, 2)->nullable()->change();
            $table->string('level', 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_nysc', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['department_id']);
            $table->dropForeign(['study_mode_id']);
            
            // Drop new columns
            $table->dropColumn([
                'title', 'city', 'religion', 'username',
                'department_id', 'level', 'study_mode_id'
            ]);
            
            // Add back payment fields
            $table->decimal('payment_amount', 10, 2)->nullable()->after('is_paid');
            $table->string('payment_reference')->nullable()->after('payment_amount');
            $table->timestamp('payment_date')->nullable()->after('payment_reference');
        });
    }
};