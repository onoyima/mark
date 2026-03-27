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
        if (Schema::hasColumn('nysc_temp_submissions', 'religion')) {
            Schema::table('nysc_temp_submissions', function (Blueprint $table) {
                $table->dropColumn('religion');
            });
        }

        if (Schema::hasColumn('student_nysc', 'religion')) {
            Schema::table('student_nysc', function (Blueprint $table) {
                $table->dropColumn('religion');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->string('religion', 100)->nullable();
        });

        Schema::table('student_nysc', function (Blueprint $table) {
            $table->string('religion', 100)->nullable();
        });
    }
};
