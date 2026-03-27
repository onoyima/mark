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
        Schema::create('nysc_data_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_nysc_id');
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('nysc_session_id')->nullable();
            $table->json('old_data');
            $table->json('new_data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nysc_data_edit_histories');
    }
};
