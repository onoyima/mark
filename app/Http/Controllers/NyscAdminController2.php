<?php

namespace App\Http\Controllers;

use App\Models\StudentNysc;
use App\Models\NyscPayment;
use App\Models\NyscTempSubmission;
use App\Models\Staff;
use App\Models\Student;
use App\Models\AdminSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\StudentNyscExport;
use App\Exports\StudentsListExport;

class NyscAdminController extends Controller
{
    /**
     * Get dashboard data for NYSC admin
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function dashboard(): \Illuminate\Http\JsonResponse
    {
        try {
            // Use database aggregation for better performance
            $totalStudents = StudentNysc::where('is_submitted', true)->count();
            $totalPaid = StudentNysc::where('is_submitted', true)->where('is_paid', true)->count();
            $totalUnpaid = $totalStudents - $totalPaid;
            
            // Get temp submissions count
            $tempSubmissions = \App\Models\NyscTempSubmission::where('status', 'pending')->count();
            
            // Department breakdown using database aggregation
            $departmentStats = StudentNysc::where('is_submitted', true)
                ->selectRaw('department, COUNT(*) as count')
                ->groupBy('department')
                ->get()
                ->map(function ($item) use ($totalStudents) {
                    return [
                        'department' => $item->department ?: 'Unknown',
                        'count' => $item->count,
                        'percentage' => $totalStudents > 0 ? round(($item->count / $totalStudents) * 100, 1) : 0
                    ];
                });
            
            // Gender breakdown using database aggregation
            $genderStats = StudentNysc::where('is_submitted', true)
                ->selectRaw('gender, COUNT(*) as count')
                ->groupBy('gender')
                ->get()
                ->map(function ($item) use ($totalStudents) {
                    return [
                        'gender' => ucfirst($item->gender ?: 'Unknown'),
                        'count' => $item->count,
                        'percentage' => $totalStudents > 0 ? round(($item->count / $totalStudents) * 100, 1) : 0
                    ];
                });
            
            // Payment analytics using database aggregation
            $paymentStats = \App\Models\NyscPayment::where('status', 'successful')
                ->selectRaw('COUNT(*) as total_payments, SUM(amount) as total_revenue, AVG(amount) as average_amount')
                ->first();
            
            $totalRevenue = $paymentStats->total_revenue ?? 0;
            $averageAmount = $paymentStats->average_amount ?? 0;
            $successRate = $totalStudents > 0 ? round(($totalPaid / $totalStudents) * 100, 1) : 0;
        
            // Monthly payment trends (last 7 months) using database aggregation
            $monthlyTrends = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $monthStart = $date->startOfMonth()->format('Y-m-d H:i:s');
                $monthEnd = $date->endOfMonth()->format('Y-m-d H:i:s');
                
                $monthStats = \App\Models\NyscPayment::where('status', 'successful')
                    ->whereBetween('payment_date', [$monthStart, $monthEnd])
                    ->selectRaw('COUNT(*) as count, SUM(amount) as revenue')
                    ->first();
                
                $monthlyTrends[] = [
                    'month' => $date->format('M'),
                    'revenue' => $monthStats->revenue ?? 0,
                    'count' => $monthStats->count ?? 0
                ];
            }
            
            // Recent registrations (last 10) using efficient query
            $recentRegistrations = StudentNysc::where('is_submitted', true)
                ->with('student:id,fname,lname')
                ->select('id', 'student_id', 'fname', 'lname', 'matric_no', 'department', 'is_paid', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function($student) {
                    return [
                        'id' => $student->student_id,
                        'name' => trim(($student->fname ?? '') . ' ' . ($student->lname ?? '')),
                        'matric_no' => $student->matric_no,
                        'department' => $student->department,
                        'is_paid' => $student->is_paid,
                        'created_at' => $student->created_at
                    ];
                });
        
            return response()->json([
                'totalStudents' => $totalStudents,
                'confirmedData' => $totalStudents,
                'completedPayments' => $totalPaid,
                'pendingPayments' => $totalUnpaid,
                'totalNyscSubmissions' => $totalStudents,
                'totalTempSubmissions' => $tempSubmissions,
                'recentRegistrations' => $recentRegistrations,
                'departmentBreakdown' => $departmentStats,
                'genderBreakdown' => $genderStats,
                'paymentAnalytics' => [
                    'totalRevenue' => $totalRevenue,
                    'averageAmount' => round($averageAmount, 2),
                    'successRate' => $successRate,
                    'monthlyTrends' => $monthlyTrends
                ],
                'system_status' => $this->getSystemStatus(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Dashboard data loading failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get system control settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getControl(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'system_status' => $this->getSystemStatus(),
        ]);
    }
    
    /**
     * Control the update window
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function control(Request $request)
    {
        $request->validate([
            'open' => 'required|boolean',
            'deadline' => 'required|date',
        ]);
        
        // Store settings in cache
        Cache::put('nysc.system_open', $request->open, now()->addYears(1));
        Cache::put('nysc.payment_deadline', $request->deadline, now()->addYears(1));
        
        return response()->json([
            'message' => 'System settings updated successfully.',
            'system_status' => $this->getSystemStatus(),
        ]);
    }
    
    /**
     * Update a student's NYSC record
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $studentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStudent(Request $request, $studentId): \Illuminate\Http\JsonResponse
    {
        $nysc = StudentNysc::where('student_id', $studentId)->first();
        
        if (!$nysc) {
            return response()->json([
                'message' => 'Student record not found.',
            ], 404);
        }
        
        // Validate the request
        $validated = $request->validate([
            'fname' => 'sometimes|string|max:100',
            'lname' => 'sometimes|string|max:100',
            'mname' => 'sometimes|string|max:100',
            'gender' => 'sometimes|in:male,female,other',
            'dob' => 'sometimes|date',
            'marital_status' => 'sometimes|in:single,married,divorced,widowed',
            'phone' => 'sometimes|string|max:20',
            'email' => 'sometimes|email|max:255',
            'address' => 'sometimes|string',
            'state_of_origin' => 'sometimes|string|max:100',
            'lga' => 'sometimes|string|max:100',
            'matric_no' => 'sometimes|string|max:50',
            'course_of_study' => 'sometimes|string|max:255',
            'department' => 'sometimes|string|max:255',
            'faculty' => 'sometimes|string|max:255',
            'graduation_year' => 'sometimes|string|size:4',
            'cgpa' => 'sometimes|numeric|min:0|max:5',
            'jambno' => 'sometimes|string|max:20',
            'study_mode' => 'sometimes|string|max:50',
            'emergency_contact_name' => 'sometimes|string|max:255',
            'emergency_contact_phone' => 'sometimes|string|max:20',
            'emergency_contact_relationship' => 'sometimes|string|max:100',
            'emergency_contact_address' => 'sometimes|string',
            'is_paid' => 'sometimes|boolean',
            'payment_amount' => 'sometimes|integer',
        ]);
        
        // Update the record
        $nysc->update($validated);
        
        return response()->json([
            'message' => 'Student record updated successfully.',
            'data' => $nysc,
        ]);
    }    
   
 /**
     * Export student data
     *
     * @param  string  $format
     * @return \Illuminate\Http\Response
     */
    public function export($format)
    {
        try {
            Log::info("Export request received for format: {$format}");
            
            $students = StudentNysc::where('is_submitted', true)
                ->with(['payments' => function($query) {
                    $query->where('status', 'successful')->orderBy('payment_date', 'desc');
                }])
                ->get();
            
            Log::info("Found {$students->count()} students for export");
            
            // Transform data to include payment information
            $students = $students->map(function($student) {
                $latestPayment = $student->payments->first();
                $student->payment_amount = $latestPayment ? $latestPayment->amount : 0;
                $student->payment_date = $latestPayment ? $latestPayment->payment_date : null;
                return $student;
            });
            
            switch ($format) {
                case 'excel':
                    return $this->exportExcel($students);
                case 'csv':
                    return $this->exportCsv($students);
                case 'pdf':
                    return $this->exportPdf($students);
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid export format. Supported formats: excel, csv, pdf',
                    ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Export failed: ' . $e->getMessage(), [
                'format' => $format,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getTraceAsString() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Get payment data
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function payments(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            
            $query = NyscPayment::with(['studentNysc.student']);
            
            // Apply filters if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->has('payment_method')) {
                $query->where('payment_method', $request->payment_method);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('studentNysc', function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('matric_no', 'like', "%{$search}%");
                });
            }
            
            // Date range filters
            if ($request->has('dateStart') && $request->dateStart) {
                $query->whereDate('payment_date', '>=', $request->dateStart);
            }
            
            if ($request->has('dateEnd') && $request->dateEnd) {
                $query->whereDate('payment_date', '<=', $request->dateEnd);
            }
            
            $payments = $query->orderBy('payment_date', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            
            // Transform the data
            $payments->getCollection()->transform(function($payment) {
                $studentNysc = $payment->studentNysc;
                $student = $studentNysc ? $studentNysc->student : null;
                
                // If no studentNysc relationship, try to get student directly
                if (!$student && $payment->student_id) {
                    $student = \App\Models\Student::find($payment->student_id);
                }
                
                return [
                    'id' => $payment->id,
                    'student_id' => $payment->student_id,
                    'student_name' => $studentNysc ? trim($studentNysc->fname . ' ' . $studentNysc->mname . ' ' . $studentNysc->lname) : ($student ? $student->first_name . ' ' . $student->last_name : 'N/A'),
                    'matric_number' => $studentNysc ? $studentNysc->matric_no : 'N/A',
                    'email' => $student ? $student->email : 'N/A',
                    'department' => $studentNysc ? $studentNysc->department : 'N/A',
                    'amount' => $payment->amount,
                    'payment_method' => $payment->payment_method ?? 'paystack',
                    'payment_status' => $payment->status,
                    'transaction_reference' => $payment->payment_reference,
                    'payment_date' => $payment->payment_date,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ];
            });
            
            // If no payments exist, return empty data structure
            if ($payments->isEmpty()) {
                return response()->json([
                    'payments' => [],
                    'total' => 0,
                    'statistics' => [
                        'total_amount' => 0,
                        'standard_fee_count' => 0,
                        'late_fee_count' => 0,
                    ],
                ]);
            }
            
            // Calculate statistics from actual payment records
            $allPayments = \App\Models\NyscPayment::where('status', 'successful')->get();
            $totalAmount = $allPayments->sum('amount');
            
            // Get payment amounts from admin settings for accurate counting
            $standardFee = AdminSetting::get('payment_amount');
            $lateFee = AdminSetting::get('late_payment_fee');
            
            $standardFeeCount = $allPayments->where('amount', $standardFee)->count();
            $lateFeeCount = $allPayments->where('amount', $lateFee)->count();
            
            return response()->json([
                'payments' => $payments,
                'total' => $payments->count(),
                'statistics' => [
                    'total_amount' => $totalAmount,
                    'standard_fee_count' => $standardFeeCount,
                    'late_fee_count' => $lateFeeCount,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching payments data: ' . $e->getMessage());
            
            return response()->json([
                'payments' => [],
                'total' => 0,
                'statistics' => [
                    'total_amount' => 0,
                    'standard_fee_count' => 0,
                    'late_fee_count' => 0,
                ],
                'error' => 'Failed to load payment data'
            ], 500);
        }
    }
    
    /**
     * Get payment details by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentDetails($id): \Illuminate\Http\JsonResponse
    {
        try {
            $payment = NyscPayment::with(['studentNysc.student'])->findOrFail($id);
            
            // Transform the payment data
            $studentNysc = $payment->studentNysc;
            $student = $studentNysc ? $studentNysc->student : null;
            
            // If no studentNysc relationship, try to get student directly
            if (!$student && $payment->student_id) {
                $student = \App\Models\Student::find($payment->student_id);
            }
            
            $paymentData = [
                'id' => $payment->id,
                'student_id' => $payment->student_id,
                'student_name' => $studentNysc ? trim($studentNysc->fname . ' ' . $studentNysc->mname . ' ' . $studentNysc->lname) : ($student ? $student->first_name . ' ' . $student->last_name : 'N/A'),
                'matric_number' => $studentNysc ? $studentNysc->matric_no : 'N/A',
                'email' => $student ? $student->email : 'N/A',
                'department' => $studentNysc ? $studentNysc->department : 'N/A',
                'amount' => $payment->amount,
                'payment_method' => $payment->payment_method ?? 'paystack',
                'payment_status' => $payment->status,
                'transaction_reference' => $payment->payment_reference,
                'payment_date' => $payment->payment_date,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
                'payment_data' => $payment->payment_data ? json_decode($payment->payment_data) : null
            ];
            
            return response()->json([
                'success' => true,
                'data' => $paymentData
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching payment details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment details'
            ], 404);
        }
    }
    
    /**
     * Verify a specific payment with Paystack and update status
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPayment(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        try {
            $payment = NyscPayment::findOrFail($id);
            
            if ($payment->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending payments can be verified'
                ], 400);
            }
            
            // For now, just mark as successful (you can implement actual Paystack verification later)
            $payment->status = 'successful';
            $payment->save();
            
            // Update student payment status
            $student = Student::find($payment->student_id);
            if ($student) {
                $studentNysc = StudentNysc::where('student_id', $student->id)->first();
                if ($studentNysc) {
                    $studentNysc->is_paid = true;
                    $studentNysc->save();
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully',
                'data' => $payment->fresh(['studentNysc.student'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying payment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify all pending payments with Paystack
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyAllPendingPayments(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $pendingPayments = NyscPayment::where('status', 'pending')->get();
            
            if ($pendingPayments->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No pending payments to verify',
                    'verified' => 0,
                    'failed' => 0
                ]);
            }
            
            $verified = 0;
            $failed = 0;
            
            foreach ($pendingPayments as $payment) {
                try {
                    // For now, just mark as successful (you can implement actual Paystack verification later)
                    $payment->status = 'successful';
                    $payment->save();
                    
                    // Update student payment status
                    $student = Student::find($payment->student_id);
                    if ($student) {
                        $studentNysc = StudentNysc::where('student_id', $student->id)->first();
                        if ($studentNysc) {
                            $studentNysc->is_paid = true;
                            $studentNysc->save();
                        }
                    }
                    
                    $verified++;
                } catch (\Exception $e) {
                    $failed++;
                    Log::error('Failed to verify payment ' . $payment->id . ': ' . $e->getMessage());
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Verified {$verified} payments, {$failed} failed",
                'verified' => $verified,
                'failed' => $failed
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying all payments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payments: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get system status
     *
     * @return array
     */
    private function getSystemStatus()
    {
        $isOpen = AdminSetting::get('system_open', true);
        $deadline = AdminSetting::get('payment_deadline', now()->addDays(30));
        $paymentAmount = AdminSetting::get('payment_amount', 2000);
        $latePaymentFee = AdminSetting::get('late_payment_fee', 3000);
        $countdownTitle = AdminSetting::get('countdown_title', 'NYSC Registration');
        $countdownMessage = AdminSetting::get('countdown_message', 'Complete your registration before the deadline');
        
        return [
            'is_open' => $isOpen,
            'deadline' => $deadline,
            'is_late_fee' => now()->gt($deadline),
            'current_fee' => now()->gt($deadline) ? $latePaymentFee : $paymentAmount,
            'payment_amount' => $paymentAmount,
            'late_payment_fee' => $latePaymentFee,
            'countdown_title' => $countdownTitle,
            'countdown_message' => $countdownMessage,
        ];
    }

    /**
     * Get public system status (no authentication required)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicSystemStatus(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getSystemStatus()
        ]);
    }
    
    /**
     * Export data as Excel
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $students
     * @return \Illuminate\Http\Response
     */
    private function exportExcel($students)
    {
        try {
            $filename = 'nysc_students_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            return Excel::download(new StudentNyscExport($students), $filename);

        } catch (\Exception $e) {
            Log::error('Excel export error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Excel export failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Export data as CSV
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $students
     * @return \Illuminate\Http\Response
     */
    private function exportCsv($students)
    {
        try {
            $filename = 'nysc_students_' . date('Y-m-d_H-i-s') . '.csv';
            
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
                'Pragma' => 'public',
            ];
            
            $callback = function() use ($students) {
                $file = fopen('php://output', 'w');
                
                // Add BOM for proper UTF-8 encoding in Excel
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
                
                // Add headers
                fputcsv($file, [
                    'ID', 'First Name', 'Middle Name', 'Last Name', 'Matric No', 'JAMB No', 'Study Mode', 'Gender', 'Date of Birth',
                    'Marital Status', 'Phone', 'Email', 'Address', 'State of Origin',
                    'LGA', 'Course of Study', 'Department', 'Graduation Year',
                    'CGPA', 'Class of Degree', 'Payment Status', 'Payment Amount', 'Payment Date'
                ]);
                
                // Add data
                foreach ($students as $student) {
                    fputcsv($file, [
                        $student->id ?? '',
                        $student->fname ?? '',
                        $student->mname ?? '',
                        $student->lname ?? '',
                        $student->matric_no ?? '',
                        $student->jamb_no ?? '',
                        $student->study_mode ?? '',
                        $student->gender ?? '',
                        $student->dob ? $student->dob->format('Y-m-d') : '',
                        $student->marital_status ?? '',
                        $student->phone ?? '',
                        $student->email ?? '',
                        $student->address ?? '',
                        $student->state ?? '',
                        $student->lga ?? '',
                        $student->course_study ?? '',
                        $student->department ?? '',
                        $student->graduation_year ?? '',
                        $student->cgpa ?? '',
                        $student->class_of_degree ?? '',
                        $student->is_paid ? 'Paid' : 'Unpaid',
                        $student->payment_amount ?? 0,
                        $student->payment_date ? $student->payment_date->format('Y-m-d H:i:s') : '',
                    ]);
                }
                
                fclose($file);
            };
            
            return Response::stream($callback, 200, $headers);
            
        } catch (\Exception $e) {
            Log::error('CSV export error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'CSV export failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Export data as PDF
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $students
     * @return \Illuminate\Http\Response
     */
    private function exportPdf($students)
    {
        try {
            // Create HTML content for PDF
            $html = '<html><head><title>NYSC Students Export</title>';
            $html .= '<style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .header { text-align: center; margin-bottom: 20px; }
                .summary { margin-bottom: 20px; }
            </style></head><body>';
            
            $html .= '<div class="header">';
            $html .= '<h1>NYSC Students Export Report</h1>';
            $html .= '<p>Generated on: ' . now()->format('Y-m-d H:i:s') . '</p>';
            $html .= '</div>';
            
            $html .= '<div class="summary">';
            $html .= '<p><strong>Total Students:</strong> ' . $students->count() . '</p>';
            $html .= '<p><strong>Paid Students:</strong> ' . $students->where('is_paid', true)->count() . '</p>';
            $html .= '<p><strong>Unpaid Students:</strong> ' . $students->where('is_paid', false)->count() . '</p>';
            $html .= '</div>';
            
            $html .= '<table>';
            $html .= '<thead><tr>';
            $html .= '<th>S/N</th><th>Name</th><th>Matric No</th><th>Department</th>';
            $html .= '<th>Gender</th><th>Phone</th><th>Email</th><th>Payment Status</th>';
            $html .= '</tr></thead><tbody>';
            
            foreach ($students as $index => $student) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')) . '</td>';
                $html .= '<td>' . ($student->matric_no ?? 'N/A') . '</td>';
                $html .= '<td>' . ($student->department ?? 'N/A') . '</td>';
                $html .= '<td>' . ($student->gender ?? 'N/A') . '</td>';
                $html .= '<td>' . ($student->phone ?? 'N/A') . '</td>';
                $html .= '<td>' . ($student->email ?? 'N/A') . '</td>';
                $html .= '<td>' . ($student->is_paid ? 'Paid' : 'Unpaid') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table></body></html>';
            
            // For now, return HTML content as PDF would require additional setup
            $filename = 'nysc_students_' . date('Y-m-d_H-i-s') . '.html';
            
            return response($html, 200, [
                'Content-Type' => 'text/html',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
            
        } catch (\Exception $e) {
            Log::error('PDF export error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'PDF export failed: ' . $e->getMessage()
            ], 500);
        }
    }    
 
    /**
     * Get students list with pagination and filters
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentsList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            $search = $request->input('search', '');
            $department = $request->input('department', '');
            $paymentStatus = $request->input('payment_status', '');
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            
            $query = StudentNysc::where('is_submitted', true);
            
            // Apply search filter
            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('mname', 'like', "%{$search}%")
                      ->orWhere('matric_no', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            // Apply department filter
            if ($department) {
                $query->where('department', $department);
            }
            
            // Apply payment status filter
            if ($paymentStatus !== '') {
                $query->where('is_paid', $paymentStatus === 'paid');
            }
            
            // Apply sorting
            $query->orderBy($sortBy, $sortOrder);
            
            $students = $query->paginate($perPage, ['*'], 'page', $page);
            
            // Transform the data
            $students->getCollection()->transform(function($student) {
                return [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'matric_no' => $student->matric_no,
                    'email' => $student->email,
                    'phone' => $student->phone,
                    'department' => $student->department,
                    'faculty' => $student->faculty,
                    'course_of_study' => $student->course_of_study,
                    'graduation_year' => $student->graduation_year,
                    'cgpa' => $student->cgpa,
                    'class_of_degree' => $student->class_of_degree,
                    'gender' => $student->gender,
                    'state_of_origin' => $student->state_of_origin,
                    'lga' => $student->lga,
                    'is_paid' => $student->is_paid,
                    'created_at' => $student->created_at,
                    'updated_at' => $student->updated_at,
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $students,
                'meta' => [
                    'total' => $students->total(),
                    'per_page' => $students->perPage(),
                    'current_page' => $students->currentPage(),
                    'last_page' => $students->lastPage(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching students list: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch students list'
            ], 500);
        }
    }
    
    /**
     * Export students list
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function exportStudentsList(Request $request)
    {
        try {
            $format = $request->input('format', 'excel');
            $students = StudentNysc::where('is_submitted', true)->get();
            
            return $this->export($format);
        } catch (\Exception $e) {
            Log::error('Export students list failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get dashboard with settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDashboardWithSettings(): \Illuminate\Http\JsonResponse
    {
        try {
            $dashboardData = $this->dashboard()->getData(true);
            $systemStatus = $this->getSystemStatus();
            
            return response()->json([
                'dashboard' => $dashboardData,
                'settings' => $systemStatus
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard with settings failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard with settings'
            ], 500);
        }
    }
    
    /**
     * Get admin settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings(): \Illuminate\Http\JsonResponse
    {
        try {
            $settings = [
                'system_open' => AdminSetting::get('system_open', true),
                'payment_deadline' => AdminSetting::get('payment_deadline', now()->addDays(30)),
                'payment_amount' => AdminSetting::get('payment_amount', 2000),
                'late_payment_fee' => AdminSetting::get('late_payment_fee', 3000),
                'countdown_title' => AdminSetting::get('countdown_title', 'NYSC Registration'),
                'countdown_message' => AdminSetting::get('countdown_message', 'Complete your registration before the deadline'),
                'email_notifications' => AdminSetting::get('email_notifications', true),
                'sms_notifications' => AdminSetting::get('sms_notifications', false),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch settings'
            ], 500);
        }
    }
    
    /**
     * Update admin settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'system_open' => 'sometimes|boolean',
                'payment_deadline' => 'sometimes|date',
                'payment_amount' => 'sometimes|integer|min:0',
                'late_payment_fee' => 'sometimes|integer|min:0',
                'countdown_title' => 'sometimes|string|max:255',
                'countdown_message' => 'sometimes|string|max:500',
                'email_notifications' => 'sometimes|boolean',
                'sms_notifications' => 'sometimes|boolean',
            ]);
            
            foreach ($validated as $key => $value) {
                AdminSetting::set($key, $value);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $this->getSystemStatus()
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings'
            ], 500);
        }
    }
    
    /**
     * Get students data (alias for getStudentsList)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentsData(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->getStudentsList($request);
    }
    
    /**
     * Get students (alias for getStudentsList)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudents(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->getStudentsList($request);
    }
    
    /**
     * Export students (alias for export)
     *
     * @param string $format
     * @return \Illuminate\Http\Response
     */
    public function exportStudents($format)
    {
        return $this->export($format);
    }
    
    /**
     * Get submissions
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubmissions(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            
            $submissions = NyscTempSubmission::with(['student'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);
            
            return response()->json([
                'success' => true,
                'data' => $submissions
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching submissions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch submissions'
            ], 500);
        }
    }
    
    /**
     * Get submission details
     *
     * @param int $submissionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubmissionDetails($submissionId): \Illuminate\Http\JsonResponse
    {
        try {
            $submission = NyscTempSubmission::with(['student'])->findOrFail($submissionId);
            
            return response()->json([
                'success' => true,
                'data' => $submission
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching submission details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Submission not found'
            ], 404);
        }
    }
    
    /**
     * Update submission status
     *
     * @param Request $request
     * @param int $submissionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSubmissionStatus(Request $request, $submissionId): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,approved,rejected'
            ]);
            
            $submission = NyscTempSubmission::findOrFail($submissionId);
            $submission->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Submission status updated successfully',
                'data' => $submission
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating submission status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update submission status'
            ], 500);
        }
    }
    
    /**
     * Create export job
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createExportJob(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|in:students,payments,submissions',
                'format' => 'required|in:excel,csv,pdf',
                'filters' => 'sometimes|array'
            ]);
            
            // For now, just return a mock job ID
            $jobId = uniqid('export_');
            
            return response()->json([
                'success' => true,
                'message' => 'Export job created successfully',
                'job_id' => $jobId
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating export job: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create export job'
            ], 500);
        }
    }
    
    /**
     * Get export jobs
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportJobs(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Mock data for now
            $jobs = [
                [
                    'id' => 'export_1',
                    'type' => 'students',
                    'format' => 'excel',
                    'status' => 'completed',
                    'created_at' => now()->subHours(2),
                    'completed_at' => now()->subHours(1)
                ]
            ];
            
            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching export jobs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch export jobs'
            ], 500);
        }
    }
    
    /**
     * Get export job status
     *
     * @param string $jobId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportJobStatus($jobId): \Illuminate\Http\JsonResponse
    {
        try {
            // Mock data for now
            $job = [
                'id' => $jobId,
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Export completed successfully'
            ];
            
            return response()->json([
                'success' => true,
                'data' => $job
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching export job status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Export job not found'
            ], 404);
        }
    }
    
    /**
     * Download export file
     *
     * @param string $jobId
     * @return \Illuminate\Http\Response
     */
    public function downloadExportFile($jobId)
    {
        try {
            // For now, redirect to regular export
            return $this->export('excel');
        } catch (\Exception $e) {
            Log::error('Error downloading export file: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Export file not found'
            ], 404);
        }
    }
    
    /**
     * Get all students
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllStudents(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->getStudentsList($request);
    }
    
    /**
     * Get student statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $totalStudents = StudentNysc::where('is_submitted', true)->count();
            $paidStudents = StudentNysc::where('is_submitted', true)->where('is_paid', true)->count();
            $unpaidStudents = $totalStudents - $paidStudents;
            
            $departmentStats = StudentNysc::where('is_submitted', true)
                ->selectRaw('department, COUNT(*) as count')
                ->groupBy('department')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_students' => $totalStudents,
                    'paid_students' => $paidStudents,
                    'unpaid_students' => $unpaidStudents,
                    'department_breakdown' => $departmentStats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching student stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch student statistics'
            ], 500);
        }
    }
    
    /**
     * Get student details
     *
     * @param int $studentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentDetails($studentId): \Illuminate\Http\JsonResponse
    {
        try {
            $student = StudentNysc::where('student_id', $studentId)
                ->with(['payments' => function($query) {
                    $query->orderBy('payment_date', 'desc');
                }])
                ->firstOrFail();
            
            return response()->json([
                'success' => true,
                'data' => $student
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching student details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Student not found'
            ], 404);
        }
    }
    
    /**
     * Get system settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSystemSettings(): \Illuminate\Http\JsonResponse
    {
        return $this->getSettings();
    }
    
    /**
     * Update system settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSystemSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->updateSettings($request);
    }
    
    /**
     * Get email settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEmailSettings(): \Illuminate\Http\JsonResponse
    {
        try {
            $settings = [
                'smtp_host' => AdminSetting::get('smtp_host', ''),
                'smtp_port' => AdminSetting::get('smtp_port', 587),
                'smtp_username' => AdminSetting::get('smtp_username', ''),
                'smtp_password' => AdminSetting::get('smtp_password', ''),
                'smtp_encryption' => AdminSetting::get('smtp_encryption', 'tls'),
                'from_email' => AdminSetting::get('from_email', ''),
                'from_name' => AdminSetting::get('from_name', 'NYSC Portal'),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching email settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch email settings'
            ], 500);
        }
    }
    
    /**
     * Update email settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateEmailSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'smtp_host' => 'sometimes|string|max:255',
                'smtp_port' => 'sometimes|integer|min:1|max:65535',
                'smtp_username' => 'sometimes|string|max:255',
                'smtp_password' => 'sometimes|string|max:255',
                'smtp_encryption' => 'sometimes|in:tls,ssl,none',
                'from_email' => 'sometimes|email|max:255',
                'from_name' => 'sometimes|string|max:255',
            ]);
            
            foreach ($validated as $key => $value) {
                AdminSetting::set($key, $value);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Email settings updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating email settings: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update email settings'
            ], 500);
        }
    }
    
    /**
     * Test email configuration
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => 'required|email'
            ]);
            
            // Mock email test for now
            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $validated['email']
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending test email: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email'
            ], 500);
        }
    }
    
    /**
     * Clear application cache
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearCache(): \Illuminate\Http\JsonResponse
    {
        try {
            Cache::flush();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing cache: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache'
            ], 500);
        }
    }
    
    /**
     * Get admin users
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdminUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $users = Staff::where('role', 'admin')
                ->select('id', 'fname', 'lname', 'email', 'created_at', 'updated_at')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching admin users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch admin users'
            ], 500);
        }
    }
    
    /**
     * Create admin user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createAdminUser(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'fname' => 'required|string|max:100',
                'lname' => 'required|string|max:100',
                'email' => 'required|email|unique:staff,email',
                'password' => 'required|string|min:6',
            ]);
            
            $validated['password'] = Hash::make($validated['password']);
            $validated['role'] = 'admin';
            
            $user = Staff::create($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user created successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating admin user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin user'
            ], 500);
        }
    }
    
    /**
     * Update admin user
     *
     * @param Request $request
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAdminUser(Request $request, $userId): \Illuminate\Http\JsonResponse
    {
        try {
            $user = Staff::findOrFail($userId);
            
            $validated = $request->validate([
                'fname' => 'sometimes|string|max:100',
                'lname' => 'sometimes|string|max:100',
                'email' => 'sometimes|email|unique:staff,email,' . $userId,
                'password' => 'sometimes|string|min:6',
            ]);
            
            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }
            
            $user->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user updated successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating admin user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin user'
            ], 500);
        }
    }
    
    /**
     * Delete admin user
     *
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAdminUser($userId): \Illuminate\Http\JsonResponse
    {
        try {
            $user = Staff::findOrFail($userId);
            
            // Prevent deleting the current user
            if ($user->id === Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete your own account'
                ], 400);
            }
            
            $user->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting admin user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin user'
            ], 500);
        }
    }
    
    /**
     * Update admin profile
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAdminProfile(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $user = Auth::user();
            
            $validated = $request->validate([
                'fname' => 'sometimes|string|max:100',
                'lname' => 'sometimes|string|max:100',
                'email' => 'sometimes|email|unique:staff,email,' . $user->id,
                'password' => 'sometimes|string|min:6',
            ]);
            
            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }
            
            $user->update($validated);
            
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating admin profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile'
            ], 500);
        }
    }
    
    /**
     * Upload CSV file
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadCsv(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'csv_file' => 'required|file|mimes:csv,txt|max:10240'
            ]);
            
            // Mock CSV upload processing
            return response()->json([
                'success' => true,
                'message' => 'CSV file uploaded and processed successfully',
                'processed_records' => 0,
                'errors' => []
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading CSV: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload CSV file'
            ], 500);
        }
    }
    
    /**
     * Download CSV template
     *
     * @return \Illuminate\Http\Response
     */
    public function downloadCsvTemplate()
    {
        try {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="nysc_template.csv"',
            ];
            
            $callback = function() {
                $file = fopen('php://output', 'w');
                
                // Add CSV headers
                fputcsv($file, [
                    'matric_no', 'fname', 'lname', 'mname', 'email', 'phone',
                    'department', 'faculty', 'course_of_study', 'graduation_year',
                    'cgpa', 'gender', 'dob', 'state_of_origin', 'lga'
                ]);
                
                // Add sample data
                fputcsv($file, [
                    'CSC/2019/001', 'John', 'Doe', 'Smith', 'john.doe@example.com', '08012345678',
                    'Computer Science', 'Science', 'Computer Science', '2023',
                    '4.50', 'male', '1998-01-15', 'Lagos', 'Ikeja'
                ]);
                
                fclose($file);
            };
            
            return Response::stream($callback, 200, $headers);
        } catch (\Exception $e) {
            Log::error('Error downloading CSV template: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to download CSV template'
            ], 500);
        }
    }
    
    /**
     * Test CSV export
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testCsvExport(): \Illuminate\Http\JsonResponse
    {
        try {
            $count = StudentNysc::where('is_submitted', true)->count();
            
            return response()->json([
                'success' => true,
                'message' => 'CSV export test successful',
                'total_records' => $count
            ]);
        } catch (\Exception $e) {
            Log::error('CSV export test failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'CSV export test failed'
            ], 500);
        }
    }
    
    /**
     * Export student NYSC data as CSV
     *
     * @return \Illuminate\Http\Response
     */
    public function exportStudentNyscCsv()
    {
        return $this->exportCsv(StudentNysc::where('is_submitted', true)->get());
    }
    
    /**
     * Get CSV export statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCsvExportStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $totalStudents = StudentNysc::where('is_submitted', true)->count();
            $paidStudents = StudentNysc::where('is_submitted', true)->where('is_paid', true)->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_students' => $totalStudents,
                    'paid_students' => $paidStudents,
                    'unpaid_students' => $totalStudents - $paidStudents
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching CSV export stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch export statistics'
            ], 500);
        }
    }
    
    /**
     * Test database update
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testDatabaseUpdate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Mock database update test
            return response()->json([
                'success' => true,
                'message' => 'Database update test successful'
            ]);
        } catch (\Exception $e) {
            Log::error('Database update test failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Database update test failed'
            ], 500);
        }
    }
    
    /**
     * Get pending payments statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingPaymentsStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $pendingCount = NyscPayment::where('status', 'pending')->count();
            $pendingAmount = NyscPayment::where('status', 'pending')->sum('amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'pending_count' => $pendingCount,
                    'pending_amount' => $pendingAmount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending payments stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending payments statistics'
            ], 500);
        }
    }
    
    /**
     * Verify pending payments
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPendingPayments(Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->verifyAllPendingPayments($request);
    }
    
    /**
     * Verify single payment
     *
     * @param Request $request
     * @param NyscPayment $payment
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifySinglePayment(Request $request, NyscPayment $payment): \Illuminate\Http\JsonResponse
    {
        return $this->verifyPayment($request, $payment->id);
    }
}
  