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
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type')->default('string'); // string, number, boolean, json, date
            $table->string('description')->nullable();
            $table->string('category')->default('general'); // general, payment, system, countdown
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['key', 'is_active']);
            $table->index('category');
        });
        
        // Insert default settings
        DB::table('admin_settings')->insert([
            [
                'key' => 'payment_amount',
                'value' => '2000.00',
                'type' => 'number',
                'description' => ' Processing payment amount',
                'category' => 'payment',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'payment_deadline',
                'value' => '2025-03-31 23:59:59',
                'type' => 'date',
                'description' => ' Processing payment deadline',
                'category' => 'payment',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'countdown_title',
                'value' => ' Processing Deadline',
                'type' => 'string',
                'description' => 'Title displayed on countdown timer',
                'category' => 'countdown',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'countdown_message',
                'value' => 'Complete your  Process before the deadline',
                'type' => 'string',
                'description' => 'Message displayed with countdown timer',
                'category' => 'countdown',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'system_open',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Whether the  system is open',
                'category' => 'system',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'late_payment_fee',
                'value' => '500.00',
                'type' => 'number',
                'description' => 'Additional fee for late payments',
                'category' => 'payment',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_settings');
    }
};