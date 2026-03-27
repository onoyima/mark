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
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->string('submission_token', 100)->nullable()->after('nysc_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->dropColumn('submission_token');
        });
    }
};
