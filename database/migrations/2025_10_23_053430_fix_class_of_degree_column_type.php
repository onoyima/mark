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
            // Change class_of_degree from int(11) to varchar(100) to store degree names
            $table->string('class_of_degree', 100)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_nysc', function (Blueprint $table) {
            // Revert back to int(11) if needed
            $table->integer('class_of_degree')->nullable()->change();
        });
    }
};
