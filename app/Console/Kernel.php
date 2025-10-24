<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
  protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        try {
            App::make(BirthdayController::class)->sendBirthdayEmails();
        } catch (\Exception $e) {
            Log::error('Birthday email sending failed: ' . $e->getMessage());
        }
    })->timezone('Africa/Lagos')->dailyAt('07:00');
    
    // Clean up expired NYSC temporary submissions daily
    $schedule->command('nysc:cleanup-temp --force')
             ->dailyAt('02:00')
             ->timezone('Africa/Lagos');
             
    // Process GRADUANDS.docx file every hour
    $schedule->command('nysc:process-graduands')
             ->hourly()
             ->timezone('Africa/Lagos');
             
    // Verify pending payments every 10 minutes
    $schedule->command('nysc:verify-pending-payments')
             ->everyTenMinutes()
             ->timezone('Africa/Lagos')
             ->withoutOverlapping(5); // Prevent overlapping runs, timeout after 5 minutes
             
    // More frequent verification during business hours (every 5 minutes)
    $schedule->command('nysc:verify-pending-payments')
             ->everyFiveMinutes()
             ->between('08:00', '18:00')
             ->timezone('Africa/Lagos')
             ->withoutOverlapping(3);
}


    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
