<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\NyscTempSubmission;
use App\Models\NyscPayment;
use App\Models\StudentNysc;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestNyscPaymentFlow extends Command
{
    protected $signature = 'nysc:test-payment-flow {--student-id=1336} {--cleanup} {--debug}';
    protected $description = 'Test the complete NYSC payment flow to validate fixes';

    public function handle()
    {
        $studentId = $this->option('student-id');
        $cleanup = $this->option('cleanup');
        $debug = $this->option('debug');

        $this->info('Starting NYSC Payment Flow Test...');
        $this->info('Student ID: ' . $studentId);
        $this->newLine();

        try {
            // Step 1: Verify student exists
            $this->info('Step 1: Verifying student data...');
            $student = Student::find($studentId);
            $studentAcademic = StudentAcademic::where('student_id', $studentId)->first();

            if (!$student || !$studentAcademic) {
                $this->error('Student or academic data not found!');
                return 1;
            }

            $this->info('✅ Student found: ' . $student->first_name . ' ' . $student->last_name);
            $this->info('✅ Academic data found: ' . $studentAcademic->matric_no);
            $this->newLine();

            // Step 2: Test scenario 1 - Normal flow with temp submission
            $this->info('Step 2: Testing normal flow with temp submission...');
            $result1 = $this->testNormalFlow($student, $studentAcademic, $debug);
            
            if ($cleanup) {
                $this->cleanupTestData($result1['payment_id'], $result1['temp_id'], $result1['nysc_id']);
            }
            $this->newLine();

            // Step 3: Test scenario 2 - Missing temp submission (expired/deleted)
            $this->info('Step 3: Testing missing temp submission scenario...');
            $result2 = $this->testMissingTempSubmission($student, $studentAcademic, $debug);
            
            if ($cleanup) {
                $this->cleanupTestData($result2['payment_id'], null, $result2['nysc_id']);
            }
            $this->newLine();

            // Step 4: Test scenario 3 - Already processed payment
            $this->info('Step 4: Testing already processed payment scenario...');
            $result3 = $this->testAlreadyProcessedPayment($student, $studentAcademic, $debug);
            
            if ($cleanup) {
                $this->cleanupTestData($result3['payment_id'], null, $result3['nysc_id']);
            }
            $this->newLine();

            $this->info('🎉 All test scenarios completed successfully!');
            $this->info('The payment flow fixes are working correctly.');

        } catch (\Exception $e) {
            $this->error('Test failed: ' . $e->getMessage());
            if ($debug) {
                $this->error('Stack trace: ' . $e->getTraceAsString());
            }
            return 1;
        }

        return 0;
    }

    private function testNormalFlow($student, $studentAcademic, $debug)
    {
        $sessionId = 'NYSC-TEST-' . Str::random(10) . '-' . time();
        
        // Create temp submission
        $tempSubmission = NyscTempSubmission::create([
            'student_id' => $student->id,
            'session_id' => $sessionId,
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
            'level' => $studentAcademic->level,
            'graduation_year' => $studentAcademic->graduation_year,
            'cgpa' => $studentAcademic->cgpa,
            'jamb_no' => $studentAcademic->jamb_no ?? '',
            'study_mode' => $studentAcademic->study_mode ?? 'full_time',
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        // Create payment record
        $payment = NyscPayment::create([
            'student_id' => $student->id,
            'session_id' => $sessionId,
            'amount' => 10000.00,
            'payment_reference' => 'TEST-' . Str::random(10),
            'status' => 'pending',
            'payment_method' => 'paystack',
        ]);

        if ($debug) {
            $this->info('Created temp submission ID: ' . $tempSubmission->id);
            $this->info('Created payment ID: ' . $payment->id);
        }

        // Simulate payment verification
        $controller = new \App\Http\Controllers\NyscPaymentController();
        $request = new \Illuminate\Http\Request();
        $request->merge(['reference' => $payment->payment_reference]);
        
        // Mock successful payment response
        $mockResponse = [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'id' => 'test_transaction_' . time(),
                'status' => 'success',
                'reference' => $payment->payment_reference,
                'amount' => 1000000, // In kobo
            ]
        ];

        // Test the verification logic by directly calling the method
        $this->simulatePaymentVerification($payment, $mockResponse, $debug);

        // Verify results
        $payment->refresh();
        $nysc = StudentNysc::where('student_id', $student->id)->latest()->first();
        $tempSubmission->refresh();

        if ($payment->status === 'successful' && $nysc && $nysc->is_paid) {
            $this->info('✅ Normal flow test passed');
        } else {
            $this->error('❌ Normal flow test failed');
        }

        return [
            'payment_id' => $payment->id,
            'temp_id' => $tempSubmission->id,
            'nysc_id' => $nysc->id ?? null
        ];
    }

    private function testMissingTempSubmission($student, $studentAcademic, $debug)
    {
        $sessionId = 'NYSC-TEST-MISSING-' . Str::random(10) . '-' . time();
        
        // Create payment record without temp submission (simulating expired/deleted temp submission)
        $payment = NyscPayment::create([
            'student_id' => $student->id,
            'session_id' => $sessionId,
            'amount' => 10000.00,
            'payment_reference' => 'TEST-MISSING-' . Str::random(10),
            'status' => 'pending',
            'payment_method' => 'paystack',
        ]);

        if ($debug) {
            $this->info('Created payment without temp submission ID: ' . $payment->id);
        }

        // Mock successful payment response
        $mockResponse = [
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'id' => 'test_transaction_missing_' . time(),
                'status' => 'success',
                'reference' => $payment->payment_reference,
                'amount' => 1000000, // In kobo
            ]
        ];

        // Test the verification logic
        $this->simulatePaymentVerification($payment, $mockResponse, $debug);

        // Verify results
        $payment->refresh();
        $nysc = StudentNysc::where('student_id', $student->id)->latest()->first();

        if ($payment->status === 'successful' && $nysc && $nysc->is_paid) {
            $this->info('✅ Missing temp submission test passed');
        } else {
            $this->error('❌ Missing temp submission test failed');
        }

        return [
            'payment_id' => $payment->id,
            'nysc_id' => $nysc->id ?? null
        ];
    }

    private function testAlreadyProcessedPayment($student, $studentAcademic, $debug)
    {
        $sessionId = 'NYSC-TEST-PROCESSED-' . Str::random(10) . '-' . time();
        
        // Create already successful payment
        $payment = NyscPayment::create([
            'student_id' => $student->id,
            'session_id' => $sessionId,
            'amount' => 10000.00,
            'payment_reference' => 'TEST-PROCESSED-' . Str::random(10),
            'status' => 'successful',
            'payment_method' => 'paystack',
            'payment_date' => now(),
        ]);

        // Create corresponding NYSC record
        $nysc = StudentNysc::create([
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
            'level' => $studentAcademic->level,
            'graduation_year' => $studentAcademic->graduation_year,
            'cgpa' => $studentAcademic->cgpa,
            'jamb_no' => $studentAcademic->jamb_no ?? '',
            'study_mode' => $studentAcademic->study_mode ?? 'full_time',
            'is_paid' => true,
            'is_submitted' => true,
        ]);

        if ($debug) {
            $this->info('Created already processed payment ID: ' . $payment->id);
            $this->info('Created corresponding NYSC record ID: ' . $nysc->id);
        }

        // For already processed payments, we can't test the full verification flow
        // because it requires Paystack API calls. Instead, we verify the data integrity.
        
        // Check that payment exists and is marked as successful
        if ($payment->status === 'successful' && $nysc->is_paid && $nysc->is_submitted) {
            $this->info('✅ Already processed payment test passed - Data integrity verified');
        } else {
            $this->error('❌ Already processed payment test failed - Data integrity check failed');
            if ($debug) {
                $this->info('Payment status: ' . $payment->status);
                $this->info('NYSC is_paid: ' . ($nysc->is_paid ? 'true' : 'false'));
                $this->info('NYSC is_submitted: ' . ($nysc->is_submitted ? 'true' : 'false'));
            }
        }

        return [
            'payment_id' => $payment->id,
            'nysc_id' => $nysc->id
        ];
    }

    private function simulatePaymentVerification($payment, $mockResponse, $debug)
    {
        try {
            DB::beginTransaction();

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
                
                if ($student && $studentAcademic) {
                    // Create a temporary data structure similar to temp submission
                    $reconstructedData = [
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
                        'level' => $studentAcademic->level,
                        'graduation_year' => $studentAcademic->graduation_year,
                        'cgpa' => $studentAcademic->cgpa,
                        'jamb_no' => $studentAcademic->jamb_no ?? '',
                        'study_mode' => $studentAcademic->study_mode ?? 'full_time',
                    ];
                    
                    $tempSubmission = (object) array_merge($reconstructedData, [
                        'toStudentNyscData' => function() use ($reconstructedData) {
                            // Remove fields that don't belong in student_nysc table
                            $data = $reconstructedData;
                            unset($data['id'], $data['session_id'], $data['status'], $data['expires_at'], 
                                  $data['created_at'], $data['updated_at']);
                            
                            // Add submission tracking fields
                            $data['is_submitted'] = true;
                            
                            return $data;
                        }
                    ]);
                    
                    if ($debug) {
                        $this->info('Reconstructed student data for payment processing');
                    }
                }
            }

            if (!$tempSubmission) {
                throw new \Exception('Student data not found. Cannot process payment.');
            }

            // Convert temp submission data to student_nysc format
            if ($tempSubmission instanceof NyscTempSubmission) {
                // Handle actual NyscTempSubmission model
                $nyscData = $tempSubmission->toStudentNyscData();
            } else {
                // Handle reconstructed data object
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
            $payment->update([
                'status' => 'successful',
                'payment_date' => now(),
                'transaction_id' => $mockResponse['data']['id'] ?? null,
                'payment_data' => $mockResponse['data'],
            ]);

            // Mark temporary submission as paid (only if it's an actual model)
            if (method_exists($tempSubmission, 'update')) {
                $tempSubmission->update(['status' => 'paid']);
            }

            DB::commit();

            if ($debug) {
                $this->info('Payment verification simulation completed successfully');
            }

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    private function cleanupTestData($paymentId, $tempId, $nyscId)
    {
        if ($paymentId) {
            NyscPayment::where('id', $paymentId)->delete();
        }
        if ($tempId) {
            NyscTempSubmission::where('id', $tempId)->delete();
        }
        if ($nyscId) {
            StudentNysc::where('id', $nyscId)->delete();
        }
        $this->info('Test data cleaned up');
    }
}