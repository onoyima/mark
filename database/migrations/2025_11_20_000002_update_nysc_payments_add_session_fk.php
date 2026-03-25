<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nysc_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('nysc_payments', 'nysc_session_id')) {
                $table->unsignedBigInteger('nysc_session_id')->nullable()->after('student_nysc_id');
                $table->index('nysc_session_id');
            }
            if (!Schema::hasColumn('nysc_payments', 'submission_token')) {
                $table->string('submission_token')->nullable()->after('nysc_session_id');
                $table->index('submission_token');
            }
        });

        DB::statement("UPDATE nysc_payments SET nysc_session_id = 1 WHERE nysc_session_id IS NULL");

        if (Schema::hasColumn('nysc_payments', 'session_id')) {
            Schema::table('nysc_payments', function (Blueprint $table) {
                $table->dropIndex(['session_id']);
                $table->dropColumn('session_id');
            });
        }

        Schema::table('nysc_payments', function (Blueprint $table) {
            $table->foreign('nysc_session_id')->references('id')->on('nysc_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nysc_payments', function (Blueprint $table) {
            $table->dropForeign(['nysc_session_id']);
            $table->dropIndex(['nysc_session_id']);
            $table->dropIndex(['submission_token']);
            $table->dropColumn('submission_token');
            $table->dropColumn('nysc_session_id');
        });

        Schema::table('nysc_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('nysc_payments', 'session_id')) {
                $table->string('session_id')->nullable()->after('student_nysc_id');
                $table->index('session_id');
            }
        });
    }
};