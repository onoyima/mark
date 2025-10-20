<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\NyscPayment;
use App\Models\NyscTempSubmission;
use App\Models\StudentNysc;

class PaystackService
{
    protected $baseUrl;
    protected $secretKey;

    public function __construct()
    {
        $this->baseUrl = config('services.paystack.payment_url');
        $this->secretKey = config('services.paystack.secret_key');
    }

    /**
     * Verify payment status with Paystack
     *
     * @param string $reference
     * @return array
     */
    public function verifyPayment($reference)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/transaction/verify/' . $reference);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            }

            Log::error('Paystack verification failed', [
                'reference' => $reference,
                'response' => $response->json()
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification failed',
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error('Paystack verification exception', [
                'reference' => $reference,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update payment status and migrate temp data if successful
     *
     * @param NyscPayment $payment
     * @return array
     */
    public function updatePaymentStatus(NyscPayment $payment)
    {
        try {
            // Verify payment with Paystack
            $verification = $this->verifyPayment($payment->payment_reference);

            if (!$verification['success']) {
                return [
                    'success' => false,
                    'message' => $verification['message'] ?? 'Payment verification failed'
                ];
            }

            $paymentData = $verification['data']['data'] ?? null;

            if (!$paymentData) {
                return [
                    'success' => false,
                    'message' => 'Invalid payment data received from Paystack'
                ];
            }

            // Check if payment is successful
            if ($paymentData['status'] === 'success') {
                // Update payment record
                $payment->status = 'successful';
                $payment->payment_data = $paymentData;
                $payment->transaction_id = $paymentData['id'] ?? $payment->transaction_id;
                $payment->payment_date = now();
                $payment->save();

                // Check if we need to migrate temp submission data
                $tempSubmission = NyscTempSubmission::where('student_id', $payment->student_id)->first();

                if ($tempSubmission) {
                    // Create or update StudentNysc record
                    $studentNysc = StudentNysc::updateOrCreate(
                        ['student_id' => $payment->student_id],
                        [
                            'is_paid' => true,
                            'matric_no' => $tempSubmission->matric_no,
                            'fname' => $tempSubmission->fname,
                            'lname' => $tempSubmission->lname,
                            'mname' => $tempSubmission->mname,
                            'gender' => $tempSubmission->gender,
                            'dob' => $tempSubmission->dob,
                            'marital_status' => $tempSubmission->marital_status,
                            'phone' => $tempSubmission->phone,
                            'email' => $tempSubmission->email,
                            'address' => $tempSubmission->address,
                            'state' => $tempSubmission->state,
                            'lga' => $tempSubmission->lga,
                            'username' => $tempSubmission->username,
                            'department' => $tempSubmission->department,
                            'course_study' => $tempSubmission->course_study,
                            'level' => $tempSubmission->level,
                            'cgpa' => $tempSubmission->cgpa,
                            'jamb_no' => $tempSubmission->jamb_no,
                            'study_mode' => $tempSubmission->study_mode,
                        ]
                    );

                    // Update payment with student_nysc_id
                    $payment->student_nysc_id = $studentNysc->id;
                    $payment->save();

                    return [
                        'success' => true,
                        'message' => 'Payment verified and data migrated successfully',
                        'payment' => $payment,
                        'student_nysc' => $studentNysc
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Payment verified successfully, but no temp data found to migrate',
                    'payment' => $payment
                ];
            } else {
                // Update payment status to failed if Paystack says it's not successful
                $payment->status = 'failed';
                $payment->payment_data = $paymentData;
                $payment->notes = 'Payment failed according to Paystack verification';
                $payment->save();

                return [
                    'success' => false,
                    'message' => 'Payment verification indicates payment failed',
                    'payment' => $payment
                ];
            }
        } catch (\Exception $e) {
            Log::error('Payment status update error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error updating payment status: ' . $e->getMessage()
            ];
        }
    }
}
