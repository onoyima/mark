<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NyscPayment;
use App\Models\Studentnysc;
use App\Models\NyscTempSubmission;
use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\CourseStudy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RecoverNyscPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nysc:recover-payments {--dry-run : Show what would be recovered without making changes} {--student-id= : Recover specific student ID only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover NYSC payments that were successful but have no corresponding student_nysc records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $specificStudentId = $this->option('student-id');
        
        $this->info('Starting NYSC Payment Recovery Process...');
        $this->info($dryRun ? 'DRY RUN MODE - No changes will be made' : 'LIVE MODE - Changes will be applied');
        
        // Find successful payments without corresponding student_nysc records
        $query = NyscPayment::where('status', 'successful')
            ->whereNull('student_nysc_id');
            
        if ($specificStudentId) {
            $query->where('student_id', $specificStudentId);
        }
        
        $orphanedPayments = $query->get();
        
        if ($orphanedPayments->isEmpty()) {
            $this->info('No orphaned successful payments found.');
            return 0;
        }
        
        $this->info("Found {$orphanedPayments->count()} orphaned successful payments.");
        
        $recovered = 0;
        $failed = 0;
        
        foreach ($orphanedPayments as $payment) {
            $this->info("\nProcessing Payment ID: {$payment->id}");
            $this->info("Student ID: {$payment->student_id}");
            $this->info("Amount: {$payment->amount}");
            $this->info("Reference: {$payment->payment_reference}");
            $this->info("Session ID: {$payment->session_id}");
            
            try {
                // Try to recover data from multiple sources
                $studentData = $this->recoverStudentData($payment);
                
                if (!$studentData) {
                    $this->error("Could not recover student data for payment {$payment->id}");
                    $failed++;
                    continue;
                }
                
                if (!$dryRun) {
                    DB::beginTransaction();
                    
                    // Create or update the NYSC record
                    $nysc = Studentnysc::updateOrCreate(
                        ['student_id' => $payment->student_id],
                        array_merge($studentData, [
                            'is_paid' => true,
                            'is_submitted' => true,
                        ])
                    );
                    
                    // Update the payment record with payment_date only
                    // Note: student_nysc_id foreign key constraint is incorrectly set to reference students table
                    // We'll leave it as NULL for now to avoid constraint violations
                    $payment->update([
                        'payment_date' => $payment->payment_date ?? $payment->created_at,
                    ]);
                    
                    DB::commit();
                    
                    $this->info("✅ Successfully recovered payment {$payment->id} -> NYSC record {$nysc->id}");
                } else {
                    $this->info("✅ Would recover payment {$payment->id} with data:");
                    $this->info("   Name: {$studentData['fname']} {$studentData['lname']}");
                    $this->info("   Matric: {$studentData['matric_no']}");
                    $this->info("   Department: {$studentData['department']}");
                }
                
                $recovered++;
                
            } catch (\Exception $e) {
                if (!$dryRun) {
                    DB::rollback();
                }
                
                $this->error("Failed to recover payment {$payment->id}: {$e->getMessage()}");
                Log::error('NYSC Payment Recovery Failed', [
                    'payment_id' => $payment->id,
                    'student_id' => $payment->student_id,
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }
        
        $this->info("\n=== Recovery Summary ===");
        $this->info("Total payments processed: {$orphanedPayments->count()}");
        $this->info("Successfully recovered: {$recovered}");
        $this->info("Failed to recover: {$failed}");
        
        if (!$dryRun && $recovered > 0) {
            Log::info('NYSC Payment Recovery Completed', [
                'total_processed' => $orphanedPayments->count(),
                'recovered' => $recovered,
                'failed' => $failed
            ]);
        }
        
        return 0;
    }
    
    /**
     * Attempt to recover student data from various sources
     */
    private function recoverStudentData($payment)
    {
        $studentId = $payment->student_id;
        
        // First, try to find a matching temp submission (even if expired)
        if ($payment->session_id) {
            $tempSubmission = NyscTempSubmission::where('session_id', $payment->session_id)->first();
            if ($tempSubmission) {
                $this->info("Found matching temp submission (status: {$tempSubmission->status})");
                return $tempSubmission->toStudentNyscData();
            }
        }
        
        // Second, try to find any temp submission for this student
        $anyTempSubmission = NyscTempSubmission::where('student_id', $studentId)
            ->orderBy('created_at', 'desc')
            ->first();
            
        if ($anyTempSubmission) {
            $this->info("Found recent temp submission for student");
            return $anyTempSubmission->toStudentNyscData();
        }
        
        // Third, try to reconstruct from student and academic data
        $student = Student::find($studentId);
        if (!$student) {
            $this->error("Student not found: {$studentId}");
            return null;
        }
        
        $academic = StudentAcademic::where('student_id', $studentId)
            ->orderBy('created_at', 'desc')
            ->first();
            
        if (!$academic) {
            $this->error("No academic record found for student: {$studentId}");
            return null;
        }
        
        // Get course study name
        $courseStudy = null;
        if ($academic->course_study_id) {
            $courseStudy = CourseStudy::find($academic->course_study_id);
        }
        
        $this->info("Reconstructing data from student and academic records");
        
        return [
            'student_id' => $studentId,
            'fname' => $student->fname ?? '',
            'lname' => $student->lname ?? '',
            'mname' => $student->mname ?? '',
            'gender' => $student->gender ?? 'Male',
            'dob' => $student->dob,
            'marital_status' => $student->marital_status ?? 'Single',
            'phone' => $student->phone ?? '',
            'email' => $student->email ?? '',
            'address' => $student->address ?? '',
            'state' => $student->state ?? '',
            'lga' => $student->lga ?? '',
            'username' => $student->email ?? '',
            'matric_no' => $student->matric_no ?? '',
            'department' => $academic->department ?? '',
            'level' => $academic->level ?? '',
            'graduation_year' => $academic->graduation_year ?? date('Y'),
            'cgpa' => $academic->cgpa ?? 0.00,
            'jamb_no' => $academic->jamb_no ?? '',
            'study_mode' => $courseStudy ? $courseStudy->name : ($academic->study_mode ?? ''),
        ];
    }
}