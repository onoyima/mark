<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

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
}


    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
