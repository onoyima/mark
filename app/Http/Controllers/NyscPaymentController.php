<?php

namespace App\Http\Controllers;

use App\Models\StudentNysc;
use App\Models\NyscPayment;
use App\Models\NyscTempSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NyscPaymentController extends Controller
{
    /**
     * Initiate payment for NYSC verification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function initiatePayment(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();
        
        // Validate session_id is provided
        $sessionId = $request->input('session_id');
        if (!$sessionId) {
            return response()->json([
                'message' => 'Session ID is required for payment initiation.',
            ], 400);
        }
        
        // Find the temporary submission
        $tempSubmission = NyscTempSubmission::where('session_id', $sessionId)
                                          ->where('student_id', $student->id)
                                          ->where('status', 'pending')
                                          ->first();
        
        if (!$tempSubmission) {
            return response()->json([
                'message' => 'Invalid session or submission has expired. Please confirm your details again.',
            ], 400);
        }
        
        // Check if submission has expired
        if ($tempSubmission->hasExpired()) {
            $tempSubmission->delete();
            return response()->json([
                'message' => 'Submission has expired. Please confirm your details again.',
            ], 400);
        }
        
        // Get payment amounts from admin settings
        $paymentAmount = \App\Models\AdminSetting::get('payment_amount', 1000);
        $latePaymentFee = \App\Models\AdminSetting::get('late_payment_fee', 10000);
        $deadline = \App\Models\AdminSetting::get('payment_deadline', now()->addDays(30));
        
        // Determine fee based on deadline
        $amount = now()->lt($deadline) ? $paymentAmount : $latePaymentFee;
        
        // Generate a unique reference
        $reference = 'VUST-' . Str::random(10);
        
        // Prepare Paystack request
        $paystackUrl = 'https://api.paystack.co/transaction/initialize';
        $paystackKey = config('services.paystack.secret_key');
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $paystackKey,
                'Content-Type' => 'application/json',
            ])->post($paystackUrl, [
                'email' => $student->username,
                'amount' => $amount * 100, // Paystack expects amount in kobo
                'reference' => $reference,
                'callback_url' => config('app.frontend_url', 'http://localhost:3000') . '/student/payment?status=success',
                'metadata' => [
                    'student_id' => $student->id,
                    'session_id' => $sessionId,
                    'matric_no' => $tempSubmission->matric_no,
                ],
            ]);
            
            $responseData = $response->json();
            
            if ($response->successful() && isset($responseData['data']['authorization_url'])) {
                // Create payment record in nysc_payments table
                NyscPayment::create([
                    'student_id' => $student->id,
                    'student_nysc_id' => null, // Will be set after successful payment
                    'payment_reference' => $reference,
                    'amount' => $amount,
                    'status' => 'pending',
                    'payment_method' => 'paystack',
                    'session_id' => $sessionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                return response()->json([
                    'message' => 'Payment initiated successfully.',
                    'payment_url' => $responseData['data']['authorization_url'],
                    'reference' => $reference,
                    'amount' => $amount,
                ]);
            } else {
                return response()->json([
                    'message' => 'Failed to initiate payment. Please try again.',
                    'error' => $responseData['message'] ?? 'Unknown error',
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while processing your payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Verify payment for NYSC verification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request): \Illuminate\Http\JsonResponse
    {
        $reference = $request->reference ?? $request->query('reference');
        
        if (!$reference) {
            return response()->json([
                'message' => 'Payment reference is required.',
            ], 400);
        }
        
        // Verify payment with Paystack
        $paystackUrl = 'https://api.paystack.co/transaction/verify/' . $reference;
        $paystackKey = config('services.paystack.secret_key');
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $paystackKey,
                'Content-Type' => 'application/json',
            ])->get($paystackUrl);
            
            $responseData = $response->json();
            
            if ($response->successful() && isset($responseData['data']['status']) && $responseData['data']['status'] === 'success') {
                // Find the payment record by reference in nysc_payments table
                $payment = NyscPayment::where('payment_reference', $reference)->first();
                
                if (!$payment) {
                    return response()->json([
                        'message' => 'Invalid payment reference.',
                    ], 400);
                }
                
                // Check if payment has already been processed successfully
                if ($payment->status === 'successful') {
                    // Payment already processed, return success response
                    $nysc = StudentNysc::where('student_id', $payment->student_id)->first();
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Payment verified and data submitted successfully.',
                        'payment_details' => [
                            'amount' => $payment->amount,
                            'reference' => $payment->payment_reference,
                            'date' => $payment->payment_date,
                            'status' => $payment->status,
                        ],
                        'nysc_record' => [
                            'id' => $nysc->id ?? null,
                            'is_submitted' => $nysc->is_submitted ?? true,
                        ]
                    ]);
                }
                
                // Find the temporary submission using session_id
                $tempSubmission = null;
                if ($payment->session_id) {
                    $tempSubmission = NyscTempSubmission::where('session_id', $payment->session_id)
                                                      ->where('status', 'pending')
                                                      ->first();
                }
                
                // If temp submission not found, try to find any temp submission for this student
                if (!$tempSubmission && $payment->student_id) {
                    $tempSubmission = NyscTempSubmission::where('student_id', $payment->student_id)
                                                      ->orderBy('created_at', 'desc')
                                                      ->first();
                }
                
                // If still no temp submission, try to reconstruct data from Student and StudentAcademic
                if (!$tempSubmission) {
                    $student = Student::find($payment->student_id);
                    $studentAcademic = StudentAcademic::where('student_id', $payment->student_id)->first();
                    
                    if (!$student || !$studentAcademic) {
                        return response()->json([
                            'message' => 'Student data not found. Cannot process payment.',
                        ], 400);
                    }
                    
                    // Create a temporary data structure similar to temp submission
                    $tempSubmission = (object) [
                        'student_id' => $student->id,
                        'first_name' => $student->first_name,
                        'last_name' => $student->last_name,
                        'dob' => $student->dob,
                        'marital_status' => $student->marital_status ?? 'single',
                        'phone' => $student->phone,
                        'email' => $student->email,
                        'address' => $student->address,
                        'state' => $student->state,
                        'lga' => $student->lga,
                        'username' => $student->username,
                        'matric_no' => $studentAcademic->matric_no,
                        'department' => $studentAcademic->department,
                        'course_study' => $studentAcademic->course_study ?? '',
                        'level' => $studentAcademic->level,
                        'graduation_year' => $studentAcademic->graduation_year,
                        'cgpa' => $studentAcademic->cgpa,
                        'jamb_no' => $studentAcademic->jamb_no ?? '',
                        'study_mode' => $studentAcademic->study_mode ?? 'full_time',
                        'toStudentNyscData' => function() use ($student, $studentAcademic) {
                            return [
                                'student_id' => $student->id,
                                'fname' => $student->first_name,
                                'lname' => $student->last_name,
                                'mname' => $student->middle_name ?? '',
                                'gender' => $student->gender,
                                'dob' => $student->dob,
                                'marital_status' => $student->marital_status ?? 'single',
                                'phone' => $student->phone,
                                'email' => $student->email,
                                'address' => $student->address,
                                'state' => $student->state,
                                'lga' => $student->lga,
                                'username' => $student->username,
                                'matric_no' => $studentAcademic->matric_no,
                                'department' => $studentAcademic->department,
                                'course_study' => $studentAcademic->course_study ?? '',
                                'level' => $studentAcademic->level,
                                'graduation_year' => $studentAcademic->graduation_year,
                                'cgpa' => $studentAcademic->cgpa,
                                'jamb_no' => $studentAcademic->jamb_no ?? '',
                                'study_mode' => $studentAcademic->study_mode ?? 'full_time',
                            ];
                        }
                    ];
                    
                    Log::info('Reconstructed student data for payment processing', [
                        'student_id' => $student->id,
                        'payment_id' => $payment->id,
                        'reason' => 'temp_submission_not_found'
                    ]);
                }
                
                try {
                    // Begin database transaction
                    \DB::beginTransaction();
                    
                    // Convert temp submission data to student_nysc format
                    if (is_object($tempSubmission) && method_exists($tempSubmission, 'toStudentNyscData')) {
                        // Handle actual NyscTempSubmission model
                        $nyscData = $tempSubmission->toStudentNyscData();
                    } else {
                        // Handle reconstructed data object - call the closure function
                        $nyscData = $tempSubmission->toStudentNyscData();
                    }
                    
                    // Create or update the NYSC record
                    $nysc = StudentNysc::updateOrCreate(
                        ['student_id' => $tempSubmission->student_id],
                        array_merge($nyscData, [
                            'is_paid' => true,
                            'is_submitted' => true,
                        ])
                    );
                    
                    // Update the payment record
                    // Note: student_nysc_id foreign key constraint is incorrectly set to reference students table
                    // We'll leave it as NULL for now to avoid constraint violations
                    $payment->update([
                        'status' => 'successful',
                        'payment_date' => now(),
                        'transaction_id' => $responseData['data']['id'] ?? null,
                        'payment_data' => $responseData['data'],
                    ]);
                    
                    // Mark temporary submission as paid (only if it's an actual model)
                    if (method_exists($tempSubmission, 'update')) {
                        $tempSubmission->update(['status' => 'paid']);
                    }
                    
                    // Log successful submission
                    Log::info('NYSC data submitted successfully after payment', [
                        'student_id' => $tempSubmission->student_id,
                        'nysc_id' => $nysc->id,
                        'payment_reference' => $reference,
                        'amount' => $payment->amount
                    ]);
                    
                    // Commit transaction
                    \DB::commit();
                    
                    return response()->json([
                        'success' => true,
                        'message' => 'Payment verified and data submitted successfully.',
                        'payment_details' => [
                            'amount' => $payment->amount,
                            'reference' => $payment->payment_reference,
                            'date' => $payment->payment_date,
                            'status' => $payment->status,
                        ],
                        'nysc_record' => [
                            'id' => $nysc->id,
                            'is_submitted' => $nysc->is_submitted,
                        ]
                    ]);
                    
                } catch (\Exception $e) {
                    // Rollback transaction on error
                    \DB::rollback();
                    
                    Log::error('Failed to process payment and submit data', [
                        'error' => $e->getMessage(),
                        'payment_reference' => $reference,
                        'session_id' => $payment->session_id
                    ]);
                    
                    return response()->json([
                        'message' => 'Payment successful but failed to submit data. Please contact support.',
                        'error' => 'Data processing failed'
                    ], 500);
                }
            } else {
                return response()->json([
                    'message' => 'Payment verification failed.',
                    'error' => $responseData['message'] ?? 'Unknown error',
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while verifying your payment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Paystack webhook notifications
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request): \Illuminate\Http\JsonResponse
    {
        // Verify the webhook signature
        $paystackSecretKey = config('services.paystack.secret_key');
        $signature = $request->header('x-paystack-signature');
        $body = $request->getContent();
        
        if (!$signature || !hash_equals($signature, hash_hmac('sha512', $body, $paystackSecretKey))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }
        
        $event = $request->json()->all();
        
        // Handle charge.success event
        if ($event['event'] === 'charge.success') {
            $data = $event['data'];
            $reference = $data['reference'];
            
            // Find the payment record by reference
            $payment = NyscPayment::where('payment_reference', $reference)->first();
            
            if ($payment && $payment->status !== 'successful') {
                // Find the temporary submission using session_id
                $tempSubmission = null;
                if ($payment->session_id) {
                    $tempSubmission = NyscTempSubmission::where('session_id', $payment->session_id)
                                                      ->where('status', 'pending')
                                                      ->first();
                }
                
                if ($tempSubmission) {
                    try {
                        // Begin database transaction
                        DB::beginTransaction();
                        
                        // Convert temp submission data to student_nysc format
                        $nyscData = $tempSubmission->toStudentNyscData();
                        
                        // Create or update the NYSC record
                        $nysc = StudentNysc::updateOrCreate(
                            ['student_id' => $tempSubmission->student_id],
                            array_merge($nyscData, [
                                'is_paid' => true,
                                'is_submitted' => true,
                            ])
                        );
                        
                        // Update the payment record
                        $payment->update([
                            'student_nysc_id' => $nysc->id,
                            'status' => 'successful',
                            'payment_date' => now(),
                            'transaction_id' => $data['id'] ?? null,
                        ]);
                        
                        // Mark temporary submission as paid
                        $tempSubmission->update(['status' => 'paid']);
                        
                        // Commit transaction
                        DB::commit();
                        
                        Log::info('Payment webhook processed successfully', [
                            'reference' => $reference,
                            'amount' => $data['amount'] / 100,
                            'student_id' => $tempSubmission->student_id,
                            'nysc_id' => $nysc->id,
                        ]);
                        
                    } catch (\Exception $e) {
                        // Rollback transaction on error
                        DB::rollback();
                        
                        Log::error('Failed to process webhook payment and submit data', [
                            'error' => $e->getMessage(),
                            'payment_reference' => $reference,
                            'session_id' => $payment->session_id
                        ]);
                    }
                } else {
                    // Fallback: just update payment status if no temp submission found
                    $payment->update([
                        'status' => 'successful',
                        'payment_date' => now(),
                        'transaction_id' => $data['id'] ?? null,
                    ]);
                    
                    Log::warning('Payment webhook processed but no temp submission found', [
                        'reference' => $reference,
                        'session_id' => $payment->session_id,
                    ]);
                }
            }
        }
        
        return response()->json(['message' => 'Webhook processed successfully']);
    }

    /**
     * Get payment history for the authenticated student
     */
    public function getPaymentHistory(Request $request)
    {
        try {
            $student = $request->user();
            
            $payments = NyscPayment::where('student_id', $student->id)
                ->with(['student', 'studentNysc'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'reference' => $payment->payment_reference,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'payment_date' => $payment->payment_date,
                        'created_at' => $payment->created_at,
                        'transaction_id' => $payment->transaction_id,
                        'payment_method' => $payment->payment_method,
                        'student' => [
                            'id' => $payment->student->id,
                            'name' => $payment->student->name,
                            'email' => $payment->student->email,
                            'matric_no' => $payment->student->matric_no,
                        ],
                        'student_nysc' => $payment->studentNysc ? [
                            'full_name' => trim(($payment->studentNysc->fname ?? '') . ' ' . ($payment->studentNysc->mname ?? '') . ' ' . ($payment->studentNysc->lname ?? '')),
                            'matric_number' => $payment->studentNysc->matric_no,
                            'username' => $payment->studentNysc->username,
                        ] : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $payments
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch payment history', [
                'error' => $e->getMessage(),
                'student_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history'
            ], 500);
        }
    }

    /**
     * Get payment receipt for printing
     */
    public function getPaymentReceipt(Request $request, $paymentId)
    {
        try {
            $student = $request->user();
            
            $payment = NyscPayment::where('id', $paymentId)
                ->where('student_id', $student->id)
                ->where('status', 'successful')
                ->with(['studentNysc'])
                ->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment receipt not found'
                ], 404);
            }

            $receiptData = [
                'payment' => [
                    'id' => $payment->id,
                    'reference' => $payment->payment_reference,
                    'amount' => $payment->amount,
                    'status' => $payment->status,
                    'payment_date' => $payment->payment_date,
                    'transaction_id' => $payment->transaction_id,
                    'payment_method' => $payment->payment_method,
                ],
                'student' => $payment->studentNysc ? [
                    'full_name' => trim(($payment->studentNysc->fname ?? '') . ' ' . ($payment->studentNysc->mname ?? '') . ' ' . ($payment->studentNysc->lname ?? '')),
                    'matric_number' => $payment->studentNysc->matric_no,
                    'username' => $payment->studentNysc->username,
                    'email' => $payment->studentNysc->email,
                    'phone' => $payment->studentNysc->phone,
                    'institution' => 'Benue State University', // Default institution
                    'course_of_study' => $payment->studentNysc->department,
                    'year_of_graduation' => $payment->studentNysc->graduation_year,
                ] : null,
                'receipt_generated_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'data' => $receiptData
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate payment receipt', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
                'student_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate payment receipt'
            ], 500);
        }
    }

    /**
     * Get updated student information from student_nysc table
     */
    public function getUpdatedStudentInfo(Request $request)
    {
        try {
            $student = $request->user();
            
            $studentNysc = StudentNysc::where('student_id', $student->id)
                ->where('is_submitted', true)
                ->first();

            if (!$studentNysc) {
                return response()->json([
                    'success' => false,
                    'message' => 'No submitted NYSC data found for this student'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $studentNysc->id,
                    'student_id' => $studentNysc->student_id,
                    // Personal Information
                    'fname' => $studentNysc->fname,
                    'lname' => $studentNysc->lname,
                    'mname' => $studentNysc->mname,
                    'full_name' => trim(($studentNysc->fname ?? '') . ' ' . ($studentNysc->mname ?? '') . ' ' . ($studentNysc->lname ?? '')),
                    'gender' => $studentNysc->gender,
                    'dob' => $studentNysc->dob,
                    'marital_status' => $studentNysc->marital_status,
                    'phone' => $studentNysc->phone,
                    'address' => $studentNysc->address,
                    'current_address' => $studentNysc->address,
                    'state' => $studentNysc->state,
                    'lga' => $studentNysc->lga,
                    'lga_of_origin' => $studentNysc->lga,
                    'username' => $studentNysc->username,
                    // Academic Information
                    'matric_no' => $studentNysc->matric_no,
                    'matric_number' => $studentNysc->matric_no,
                    'institution' => 'Veritas University Abuja',
                    'course_study' => $studentNysc->course_study,
                    'department' => $studentNysc->department,
                    'faculty' => null, // Field not available in current table
                    'level' => $studentNysc->level,
                    'graduation_year' => $studentNysc->graduation_year,
                    'year_of_graduation' => $studentNysc->graduation_year,
                    'degree_class' => 'Not Specified', // Field not available in current table
                    'cgpa' => $studentNysc->cgpa,
                    'jamb_no' => $studentNysc->jamb_no,
                    'study_mode' => $studentNysc->study_mode,

                    // Emergency Contact Information (not available in current table)
                    'emergency_contact_name' => null,
                    'emergency_contact_phone' => null,
                    'emergency_contact_relationship' => null,
                    'emergency_contact_address' => null,
                    // Medical Information (not available in current table structure)
                    'blood_group' => null,
                    'genotype' => null,
                    'height' => null,
                    'weight' => null,
                    // Payment and Submission Status
                    'is_paid' => $studentNysc->is_paid,

                    'payment_amount' => null, // Field not available in current table
                    'payment_reference' => null, // Field not available in current table
                    'payment_date' => null, // Field not available in current table
                    'is_submitted' => $studentNysc->is_submitted,
                    'submitted_at' => null, // Field not available in current table
                    'created_at' => $studentNysc->created_at,
                    'updated_at' => $studentNysc->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch updated student info', [
                'error' => $e->getMessage(),
                'student_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch updated student information'
            ], 500);
        }
    }
}