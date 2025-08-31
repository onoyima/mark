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
    
    // Payment Settings
    'payment_deadline' => env('NYSC_PAYMENT_DEADLINE', now()->addDays(30)),
    'standard_fee' => env('NYSC_STANDARD_FEE', 500),
    'late_fee' => env('NYSC_LATE_FEE', 10000),
    
    // Export Settings
    'export_chunk_size' => env('NYSC_EXPORT_CHUNK_SIZE', 1000),
];