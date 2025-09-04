<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NYSC System Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains configuration settings for the NYSC student verification
    | system. These settings control the behavior of the system, including
    | payment deadlines and system availability.
    |
    */

    // System Status
    'system_open' => env('NYSC_SYSTEM_OPEN', true),
    
    // Payment Settings - All payment settings are now managed through AdminSetting model
    // 'payment_deadline', 'payment_amount', and 'late_payment_fee' are stored in admin_settings table
    
    // Export Settings
    'export_chunk_size' => env('NYSC_EXPORT_CHUNK_SIZE', 1000),
];