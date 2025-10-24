<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\VerifyPendingPayments;
use App\Models\NyscPayment;
use Carbon\Carbon;

class VerifyPendingPaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nysc:verify-pending-payments 
                            {--force : Force verification of all pending payments regardless of age}
                            {--limit=50 : Limit number of payments to check}
                            {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify pending NYSC payments with Paystack and update their status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Starting NYSC Pending Payments Verification');
        $this->newLine();

        $force = $this->option('force');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🧪 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Build query for pending payments
        $query = NyscPayment::where('status', 'pending');

        if (!$force) {
            // Only check payments older than 5 minutes
            $query->where('created_at', '<=', Carbon::now()->subMinutes(5));
        }

        // Only check payments from last 7 days to avoid old transactions
        $query->where('created_at', '>=', Carbon::now()->subDays(7));

        $pendingPayments = $query->orderBy('created_at', 'desc')
                                ->limit($limit)
                                ->get();

        if ($pendingPayments->isEmpty()) {
            $this->info('✅ No pending payments found to verify');
            return 0;
        }

        $this->info("📋 Found {$pendingPayments->count()} pending payments to verify");
        $this->newLine();

        if ($dryRun) {
            $this->table(
                ['ID', 'Reference', 'Amount', 'Student ID', 'Created At', 'Age (minutes)'],
                $pendingPayments->map(function ($payment) {
                    return [
                        $payment->id,
                        $payment->reference,
                        '₦' . number_format($payment->amount / 100, 2),
                        $payment->student_nysc_id,
                        $payment->created_at->format('Y-m-d H:i:s'),
                        $payment->created_at->diffInMinutes(Carbon::now())
                    ];
                })
            );

            $this->newLine();
            $this->info('🧪 Dry run completed. Remove --dry-run to actually verify payments.');
            return 0;
        }

        // Confirm before proceeding (skip in scheduled mode)
        if (!app()->runningInConsole() || $this->confirm('Do you want to proceed with verifying these payments?')) {
            $this->newLine();
            $this->info('🚀 Dispatching verification job...');

            // Dispatch the job
            VerifyPendingPayments::dispatch();

            $this->info('✅ Verification job dispatched successfully');
            $this->info('📊 Check the logs for detailed results');
        } else {
            $this->info('❌ Operation cancelled');
            return 0;
        }
        
        $this->newLine();
        $this->comment('💡 You can also run this command with:');
        $this->comment('   --force     : Verify all pending payments regardless of age');
        $this->comment('   --limit=N   : Limit number of payments to check');
        $this->comment('   --dry-run   : Preview what would be done');

        return 0;
    }
}