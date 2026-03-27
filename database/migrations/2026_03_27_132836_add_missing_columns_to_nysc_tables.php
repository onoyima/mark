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
        // Add religion to nysc_temp_submissions if missing
        if (!Schema::hasColumn('nysc_temp_submissions', 'religion')) {
            Schema::table('nysc_temp_submissions', function (Blueprint $table) {
                $table->string('religion', 100)->nullable()->after('lga');
            });
        }

        // Add multiple columns to student_nysc if missing
        Schema::table('student_nysc', function (Blueprint $table) {
            if (!Schema::hasColumn('student_nysc', 'submission_token')) {
                $table->string('submission_token', 100)->nullable()->after('nysc_session_id');
            }
            if (!Schema::hasColumn('student_nysc', 'religion')) {
                $table->string('religion', 100)->nullable()->after('lga');
            }
            if (!Schema::hasColumn('student_nysc', 'is_military')) {
                $table->boolean('is_military')->default(false)->after('jamb_admission_letter');
            }
            if (!Schema::hasColumn('student_nysc', 'is_status')) {
                $table->boolean('is_status')->default(true)->after('is_military');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->dropColumn(['religion']);
        });

        Schema::table('student_nysc', function (Blueprint $table) {
            $table->dropColumn(['submission_token', 'religion', 'is_military', 'is_status']);
        });
    }
};
