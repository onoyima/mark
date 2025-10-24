<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\NyscPayment;
use App\Models\StudentNysc;
use Carbon\Carbon;

class VerifyPendingPayments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout
    public $tries = 3;     // Retry 3 times if failed

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting automatic verification of pending payments');

        try {
            // Get all pending payments that are older than 5 minutes
            // This prevents checking payments that just started
            $pendingPayments = NyscPayment::where('status', 'pending')
                ->where('created_at', '<=', Carbon::now()->subMinutes(5))
                ->where('created_at', '>=', Carbon::now()->subDays(7)) // Only check payments from last 7 days
                ->orderBy('created_at', 'desc')
                ->get();

            Log::info('Found pending payments to verify', [
                'count' => $pendingPayments->count()
            ]);

            $verifiedCount = 0;
            $successfulCount = 0;
            $failedCount = 0;
            $errorCount = 0;

            foreach ($pendingPayments as $payment) {
                try {
                    $result = $this->verifyPaymentWithPaystack($payment);
                    
                    if ($result['verified']) {
                        $verifiedCount++;
                        
                        if ($result['status'] === 'success') {
                            $successfulCount++;
                            $this->processSuccessfulPayment($payment, $result['data']);
                        } else {
                            $failedCount++;
                            $this->processFailedPayment($payment, $result['data']);
                        }
                    }
                    
                    // Add small delay between API calls to avoid rate limiting
                    usleep(500000); // 0.5 second delay
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('Error verifying individual payment', [
                        'payment_id' => $payment->id,
                        'reference' => $payment->reference,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('Completed automatic payment verification', [
                'total_checked' => $pendingPayments->count(),
                'verified' => $verifiedCount,
                'successful' => $successfulCount,
                'failed' => $failedCount,
                'errors' => $errorCount
            ]);

        } catch (\Exception $e) {
            Log::error('Error in automatic payment verification job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e; // Re-throw to trigger job retry
        }
    }

    /**
     * Verify payment status with Paystack API
     */
    private function verifyPaymentWithPaystack(NyscPayment $payment): array
    {
        try {
            $secretKey = config('services.paystack.secret_key');
            
            if (!$secretKey) {
                throw new \Exception('Paystack secret key not configured');
            }

            Log::info('Verifying payment with Paystack', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $secretKey,
                'Content-Type' => 'application/json',
            ])->get("https://api.paystack.co/transaction/verify/{$payment->reference}");

            if (!$response->successful()) {
                Log::warning('Paystack API request failed', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'status_code' => $response->status(),
                    'response' => $response->body()
                ]);
                
                return ['verified' => false, 'error' => 'API request failed'];
            }

            $data = $response->json();

            if (!$data['status']) {
                Log::warning('Paystack verification failed', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'message' => $data['message'] ?? 'Unknown error'
                ]);
                
                return ['verified' => false, 'error' => $data['message'] ?? 'Verification failed'];
            }

            $transactionData = $data['data'];
            $paystackStatus = $transactionData['status'];

            Log::info('Paystack verification response', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'paystack_status' => $paystackStatus,
                'amount' => $transactionData['amount'] ?? null,
                'paid_at' => $transactionData['paid_at'] ?? null
            ]);

            // Map Paystack status to our status
            $ourStatus = $this->mapPaystackStatus($paystackStatus);

            return [
                'verified' => true,
                'status' => $ourStatus,
                'data' => $transactionData
            ];

        } catch (\Exception $e) {
            Log::error('Exception during Paystack verification', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage()
            ]);
            
            return ['verified' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Map Paystack status to our internal status
     */
    private function mapPaystackStatus(string $paystackStatus): string
    {
        switch (strtolower($paystackStatus)) {
            case 'success':
                return 'success';
            case 'failed':
            case 'cancelled':
            case 'abandoned':
                return 'failed';
            case 'pending':
            case 'ongoing':
                return 'pending';
            default:
                return 'failed';
        }
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(NyscPayment $payment, array $transactionData): void
    {
        try {
            Log::info('Processing successful payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference
            ]);

            // Update payment record
            $payment->update([
                'status' => 'successful',
                'payment_date' => $transactionData['paid_at'] ? 
                    Carbon::parse($transactionData['paid_at']) : 
                    Carbon::now(),
                'gateway_response' => json_encode($transactionData),
                'verified_at' => Carbon::now()
            ]);

            // Update student NYSC record
            $studentNysc = StudentNysc::find($payment->student_nysc_id);
            if ($studentNysc) {
                $studentNysc->update([
                    'is_paid' => true,
                    'is_submitted' => true,
                    'submitted_at' => Carbon::now()
                ]);

                Log::info('Student NYSC record updated for successful payment', [
                    'student_nysc_id' => $studentNysc->id,
                    'payment_id' => $payment->id
                ]);
            } else {
                Log::warning('Student NYSC record not found for successful payment', [
                    'student_nysc_id' => $payment->student_nysc_id,
                    'payment_id' => $payment->id
                ]);
            }

            Log::info('Successfully processed payment verification', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => $transactionData['amount'] ?? null
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing successful payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process failed payment
     */
    private function processFailedPayment(NyscPayment $payment, array $transactionData): void
    {
        try {
            Log::info('Processing failed payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference
            ]);

            // Update payment record
            $payment->update([
                'status' => 'failed',
                'gateway_response' => json_encode($transactionData),
                'verified_at' => Carbon::now()
            ]);

            // Note: We don't update student record for failed payments
            // Student can try payment again

            Log::info('Failed payment processed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing failed payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('VerifyPendingPayments job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}