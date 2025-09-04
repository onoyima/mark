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
            $table->string('course_study')->nullable()->after('department');
        });
        
        Schema::table('student_nysc', function (Blueprint $table) {
            $table->string('course_study')->nullable()->after('department');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nysc_temp_submissions', function (Blueprint $table) {
            $table->dropColumn('course_study');
        });
        
        Schema::table('student_nysc', function (Blueprint $table) {
            $table->dropColumn('course_study');
        });
    }
};
