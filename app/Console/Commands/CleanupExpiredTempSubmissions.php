<?php

namespace App\Console\Commands;

use App\Models\NyscTempSubmission;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupExpiredTempSubmissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nysc:cleanup-temp {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired temporary NYSC submissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting cleanup of expired temporary submissions...');
        
        try {
            // Find pending submissions that have expired
            $expiredPending = NyscTempSubmission::where('status', 'pending')
                ->where(function ($query) {
                    $query->where('expires_at', '<', Carbon::now())
                          ->orWhere('created_at', '<', Carbon::now()->subHours(24));
                })
                ->get();
            
            // Find old completed/expired submissions (older than 7 days)
            $oldSubmissions = NyscTempSubmission::whereIn('status', ['completed', 'expired'])
                ->where('created_at', '<', Carbon::now()->subDays(7))
                ->get();
            
            $expiredCount = $expiredPending->count();
            $oldCount = $oldSubmissions->count();
            $totalCount = $expiredCount + $oldCount;
            
            if ($totalCount === 0) {
                $this->info('No expired or old temporary submissions found.');
                return 0;
            }
            
            $this->info("Found {$expiredCount} expired pending submissions and {$oldCount} old submissions.");
            
            if (!$this->option('force')) {
                if (!$this->confirm("Do you want to clean up these {$totalCount} submissions?")) {
                    $this->info('Cleanup cancelled.');
                    return 0;
                }
            }
            
            // Mark expired pending submissions as expired
            $expiredUpdated = 0;
            if ($expiredCount > 0) {
                $expiredUpdated = NyscTempSubmission::where('status', 'pending')
                    ->where(function ($query) {
                        $query->where('expires_at', '<', Carbon::now())
                              ->orWhere('created_at', '<', Carbon::now()->subHours(24));
                    })
                    ->update(['status' => 'expired']);
            }
            
            // Delete old submissions
            $deleted = 0;
            if ($oldCount > 0) {
                $deleted = NyscTempSubmission::whereIn('status', ['completed', 'expired'])
                    ->where('created_at', '<', Carbon::now()->subDays(7))
                    ->delete();
            }
            
            Log::info('Cleaned up temporary NYSC submissions', [
                'expired_marked' => $expiredUpdated,
                'old_deleted' => $deleted,
                'total_processed' => $expiredUpdated + $deleted
            ]);
            
            $this->info("Successfully marked {$expiredUpdated} submissions as expired.");
            $this->info("Successfully deleted {$deleted} old submissions.");
            $this->info("Total processed: " . ($expiredUpdated + $deleted));
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('Failed to cleanup submissions: ' . $e->getMessage());
            Log::error('Failed to cleanup temporary submissions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return 1;
        }
    }
}
