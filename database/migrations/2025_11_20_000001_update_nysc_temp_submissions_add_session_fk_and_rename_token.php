<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('nysc_temp_submissions', 'submission_token')) {
                $table->string('submission_token')->nullable()->after('student_id');
            }
            if (!Schema::hasColumn('nysc_temp_submissions', 'nysc_session_id')) {
                $table->unsignedBigInteger('nysc_session_id')->nullable()->after('submission_token');
                $table->index('nysc_session_id');
            }
        });

        if (Schema::hasColumn('nysc_temp_submissions', 'session_id')) {
            DB::statement("UPDATE nysc_temp_submissions SET submission_token = session_id WHERE submission_token IS NULL");
            Schema::table('nysc_temp_submissions', function (Blueprint $table) {
                $table->dropUnique(['session_id']);
            });
        }

        DB::statement("UPDATE nysc_temp_submissions SET nysc_session_id = 1 WHERE nysc_session_id IS NULL");

        if (Schema::hasColumn('nysc_temp_submissions', 'session_id')) {
            Schema::table('nysc_temp_submissions', function (Blueprint $table) {
                $table->dropColumn('session_id');
            });
        }

        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->unique('submission_token');
            $table->foreign('nysc_session_id')->references('id')->on('nysc_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('nysc_temp_submissions', 'session_id')) {
                $table->string('session_id')->nullable()->after('student_id');
            }
        });

        DB::statement("UPDATE nysc_temp_submissions SET session_id = submission_token WHERE session_id IS NULL");

        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->dropForeign(['nysc_session_id']);
            $table->dropUnique(['submission_token']);
            $table->dropColumn('nysc_session_id');
            $table->dropColumn('submission_token');
        });
    }
};