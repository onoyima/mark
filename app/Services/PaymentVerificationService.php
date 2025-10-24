<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\NyscPayment;
use App\Models\StudentNysc;
use Carbon\Carbon;

class PaymentVerificationService
{
    /**
     * Verify a single payment with Paystack
     */
    public function verifySinglePayment(NyscPayment $payment): array
    {
        try {
            Log::info('Verifying single payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'current_status' => $payment->status
            ]);

            $result = $this->callPaystackVerificationAPI($payment->reference);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'payment' => $payment
                ];
            }

            $transactionData = $result['data'];
            $newStatus = $this->mapPaystackStatus($transactionData['status']);

            // Only update if status has changed
            if ($payment->status !== $newStatus) {
                $this->updatePaymentStatus($payment, $newStatus, $transactionData);
                
                return [
                    'success' => true,
                    'message' => "Payment status updated from {$payment->status} to {$newStatus}",
                    'old_status' => $payment->status,
                    'new_status' => $newStatus,
                    'payment' => $payment->fresh()
                ];
            }

            return [
                'success' => true,
                'message' => 'Payment status unchanged',
                'status' => $payment->status,
                'payment' => $payment
            ];

        } catch (\Exception $e) {
            Log::error('Error verifying single payment', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
                'payment' => $payment
            ];
        }
    }

    /**
     * Verify multiple payments in batch
     */
    public function verifyBatchPayments(array $paymentIds): array
    {
        $results = [
            'total' => count($paymentIds),
            'verified' => 0,
            'updated' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($paymentIds as $paymentId) {
            try {
                $payment = NyscPayment::find($paymentId);
                
                if (!$payment) {
                    $results['errors']++;
                    $results['details'][] = [
                        'payment_id' => $paymentId,
                        'status' => 'error',
                        'message' => 'Payment not found'
                    ];
                    continue;
                }

                $result = $this->verifySinglePayment($payment);
                $results['verified']++;

                if ($result['success']) {
                    if (isset($result['new_status'])) {
                        $results['updated']++;
                        
                        if ($result['new_status'] === 'successful') {
                            $results['successful']++;
                        } elseif ($result['new_status'] === 'failed') {
                            $results['failed']++;
                        }
                    }
                } else {
                    $results['errors']++;
                }

                $results['details'][] = [
                    'payment_id' => $paymentId,
                    'reference' => $payment->reference,
                    'status' => $result['success'] ? 'verified' : 'error',
                    'message' => $result['message']
                ];

                // Small delay to avoid rate limiting
                usleep(300000); // 0.3 seconds

            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = [
                    'payment_id' => $paymentId,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Call Paystack verification API
     */
    private function callPaystackVerificationAPI(string $reference): array
    {
        try {
            $secretKey = config('services.paystack.secret_key');
            
            if (!$secretKey) {
                throw new \Exception('Paystack secret key not configured');
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->get("https://api.paystack.co/transaction/verify/{$reference}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => "API request failed with status {$response->status()}"
                ];
            }

            $data = $response->json();

            if (!$data['status']) {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Verification failed'
                ];
            }

            return [
                'success' => true,
                'data' => $data['data']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Update payment status and related records
     */
    private function updatePaymentStatus(NyscPayment $payment, string $newStatus, array $transactionData): void
    {
        // Update payment record
        $updateData = [
            'status' => $newStatus,
            'gateway_response' => json_encode($transactionData),
            'verified_at' => Carbon::now()
        ];

        if (isset($transactionData['paid_at']) && $transactionData['paid_at']) {
            $updateData['payment_date'] = Carbon::parse($transactionData['paid_at']);
        }

        $payment->update($updateData);

        // If payment is successful, update student record
        if ($newStatus === 'successful') {
            $this->processSuccessfulPayment($payment);
        }

        Log::info('Payment status updated', [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'new_status' => $newStatus,
            'amount' => $transactionData['amount'] ?? null
        ]);
    }

    /**
     * Process successful payment
     */
    private function processSuccessfulPayment(NyscPayment $payment): void
    {
        $studentNysc = StudentNysc::find($payment->student_nysc_id);
        
        if ($studentNysc && !$studentNysc->is_paid) {
            $studentNysc->update([
                'is_paid' => true,
                'is_submitted' => true,
                'submitted_at' => Carbon::now()
            ]);

            Log::info('Student NYSC record updated for successful payment', [
                'student_nysc_id' => $studentNysc->id,
                'payment_id' => $payment->id,
                'matric_no' => $studentNysc->matric_no
            ]);
        }
    }

    /**
     * Map Paystack status to internal status
     */
    private function mapPaystackStatus(string $paystackStatus): string
    {
        return match (strtolower($paystackStatus)) {
            'success' => 'successful',
            'failed', 'cancelled', 'abandoned' => 'failed',
            'pending', 'ongoing' => 'pending',
            default => 'failed'
        };
    }

    /**
     * Get pending payments statistics
     */
    public function getPendingPaymentsStats(): array
    {
        $now = Carbon::now();
        
        return [
            'total_pending' => NyscPayment::where('status', 'pending')->count(),
            'pending_last_hour' => NyscPayment::where('status', 'pending')
                ->where('created_at', '>=', $now->subHour())
                ->count(),
            'pending_last_24h' => NyscPayment::where('status', 'pending')
                ->where('created_at', '>=', $now->subDay())
                ->count(),
            'pending_older_than_5min' => NyscPayment::where('status', 'pending')
                ->where('created_at', '<=', $now->subMinutes(5))
                ->count(),
            'oldest_pending' => NyscPayment::where('status', 'pending')
                ->orderBy('created_at', 'asc')
                ->first()?->created_at?->diffForHumans(),
        ];
    }
}