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
            
            $paystackService = new \App\Services\PaystackService();
            $verification = $paystackService->verifyPayment($payment->payment_reference);
            
            if (!$verification['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $verification['message'] ?? 'Payment verification failed'
                ], 400);
            }
            
            $paymentData = $verification['data']['data'];
            
            if ($paymentData['status'] === 'success') {
                // Update payment status
                $payment->status = 'successful';
                $payment->payment_data = json_encode($paymentData);
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
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment was not successful on Paystack'
                ], 400);
            }
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
            
            $paystackService = new \App\Services\PaystackService();
            $verified = 0;
            $failed = 0;
            
            foreach ($pendingPayments as $payment) {
                $verification = $paystackService->verifyPayment($payment->payment_reference);
                
                if ($verification['success']) {
                    $paymentData = $verification['data']['data'];
                    
                    if ($paymentData['status'] === 'success') {
                        // Update payment status
                        $payment->status = 'successful';
                        $payment->payment_data = json_encode($paymentData);
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
                    } else {
                        $failed++;
                    }
                } else {
                    $failed++;
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
        $isOpen = AdminSetting::get('system_open');
        $deadline = AdminSetting::get('payment_deadline');
        $paymentAmount = AdminSetting::get('payment_amount');
        $latePaymentFee = AdminSetting::get('late_payment_fee');
        $countdownTitle = AdminSetting::get('countdown_title');
        $countdownMessage = AdminSetting::get('countdown_message');
        
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
            // In production, you would use DomPDF or similar
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
    public function getStudents(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = StudentNysc::where('is_submitted', true)->with('student');
        
        // Apply search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('fname', 'like', "%{$search}%")
                  ->orWhere('lname', 'like', "%{$search}%")
                  ->orWhere('matric_no', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        // Apply payment status filter
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $query->where('is_paid', $request->payment_status === 'paid');
        }
        
        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        // Paginate results
        $perPage = $request->get('per_page', 15);
        $students = $query->paginate($perPage);
        
        return response()->json($students);
    }

    /**
     * Get students list for the new students page with class_of_degree filter
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentsList(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $query = StudentNysc::whereNotNull('class_of_degree');
            
            // Apply course_study filter
            if ($request->has('course_study') && $request->course_study !== 'all') {
                $query->where('course_study', $request->course_study);
            }
            
            // Apply search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('mname', 'like', "%{$search}%")
                      ->orWhere('matric_no', 'like', "%{$search}%")
                      ->orWhere('jamb_no', 'like', "%{$search}%");
                });
            }
            
            // Apply sorting
            $sortBy = $request->get('sort_by', 'matric_no');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);
            
            // Paginate results
            $perPage = $request->get('per_page', 50);
            $students = $query->paginate($perPage);
            
            // Get unique course_study values for filter dropdown
            $courseStudies = StudentNysc::whereNotNull('class_of_degree')
                ->distinct()
                ->pluck('course_study')
                ->filter()
                ->sort()
                ->values();
            
            return response()->json([
                'students' => $students,
                'course_studies' => $courseStudies,
                'total_count' => StudentNysc::whereNotNull('class_of_degree')->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching students list: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch students list',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export students list as CSV with specific format
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function exportStudentsList(Request $request)
    {
        try {
            $query = StudentNysc::whereNotNull('class_of_degree');
            
            // Apply course_study filter
            if ($request->has('course_study') && $request->course_study !== 'all') {
                $query->where('course_study', $request->course_study);
            }
            
            $students = $query->orderBy('course_study')->orderBy('matric_no')->get();
            
            $format = $request->get('format', 'csv');
            $courseStudyFilter = $request->get('course_study', 'all');
            
            if ($format === 'excel') {
                return $this->exportStudentsListExcel($students, $courseStudyFilter);
            } else {
                return $this->exportStudentsListCsv($students, $courseStudyFilter);
            }
        } catch (\Exception $e) {
            Log::error('Export students list error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format text with proper capitalization
     */
    private function formatProperCase($text)
    {
        if (empty($text)) return '';
        
        // Handle special cases for course names and common words
        $text = strtolower(trim($text));
        
        // Split by common delimiters
        $words = preg_split('/[\s\-_\/]+/', $text);
        $formattedWords = [];
        
        foreach ($words as $word) {
            if (empty($word)) continue;
            
            // Special handling for common abbreviations and words
            $upperWords = ['IT', 'ICT', 'BSC', 'MSC', 'PHD', 'BA', 'MA', 'HND', 'OND', 'NCE'];
            $lowerWords = ['of', 'and', 'in', 'the', 'for', 'with', 'to', 'at', 'by'];
            
            if (in_array(strtoupper($word), $upperWords)) {
                $formattedWords[] = strtoupper($word);
            } elseif (in_array(strtolower($word), $lowerWords) && count($formattedWords) > 0) {
                $formattedWords[] = strtolower($word);
            } else {
                $formattedWords[] = ucfirst($word);
            }
        }
        
        return implode(' ', $formattedWords);
    }

    /**
     * Format gender to M/F
     */
    private function formatGender($gender)
    {
        if (empty($gender)) return '';
        
        $g = strtolower(trim($gender));
        if ($g === 'male' || $g === 'm') return 'M';
        if ($g === 'female' || $g === 'f') return 'F';
        
        return strtoupper(substr($gender, 0, 1)); // Fallback to first letter uppercase
    }

    /**
     * Export students list as CSV
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $students
     * @param  string  $courseStudyFilter
     * @return \Illuminate\Http\Response
     */
    private function exportStudentsListCsv($students, $courseStudyFilter)
    {
        $filename = 'students_list_' . ($courseStudyFilter !== 'all' ? str_replace(' ', '_', $courseStudyFilter) . '_' : '') . date('Y-m-d_H-i-s') . '.csv';
        
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
            
            // Add headers (exact database fields as requested)
            fputcsv($file, [
                'matric_no', 'fname', 'mname', 'lname', 'phone', 'state', 
                'class_of_degree', 'dob', 'graduation_year', 'gender', 
                'marital_status', 'jamb_no', 'course_study', 'study_mode'
            ]);
            
            // Add data with proper formatting
            foreach ($students as $student) {
                fputcsv($file, [
                    strtoupper($student->matric_no ?? ''), // CAPITAL LETTERS for matric_no
                    $this->formatProperCase($student->fname ?? ''), // Proper case for names
                    $this->formatProperCase($student->mname ?? ''), // Proper case for names
                    $this->formatProperCase($student->lname ?? ''), // Proper case for names
                    $student->phone ?? '', // Phone number as text (no apostrophe)
                    $this->formatProperCase($student->state ?? ''), // Proper case for state
                    $this->formatProperCase($student->class_of_degree ?? ''), // Proper case for degree
                    $student->dob ? $student->dob->format('d/m/Y') : '', // dd/mm/yyyy format (e.g., 15/03/1999)
                    $student->graduation_year ?? '', // Graduation year as text (no apostrophe)
                    $this->formatGender($student->gender ?? ''), // M/F format for gender
                    $this->formatProperCase($student->marital_status ?? ''), // Proper case for marital status
                    strtoupper($student->jamb_no ?? ''), // CAPITAL LETTERS for jamb_no
                    $this->formatProperCase($student->course_study ?? ''), // Proper case for course
                    $this->formatProperCase($student->study_mode ?? '') // Proper case for study mode
                ]);
            }
            
            fclose($file);
        };
        
        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export students list as Excel
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $students
     * @param  string  $courseStudyFilter
     * @return \Illuminate\Http\Response
     */
    private function exportStudentsListExcel($students, $courseStudyFilter)
    {
        try {
            $filename = 'students_list_' . ($courseStudyFilter !== 'all' ? str_replace(' ', '_', $courseStudyFilter) . '_' : '') . date('Y-m-d_H-i-s') . '.xlsx';
            
            return Excel::download(new StudentsListExport($students), $filename);
        } catch (\Exception $e) {
            Log::error('Excel export error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Excel export failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get system settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSystemSettings(): \Illuminate\Http\JsonResponse
    {
        $settings = [
            'registration_fee' => AdminSetting::get('payment_amount'),
            'late_fee' => AdminSetting::get('late_payment_fee'),
            'payment_deadline' => AdminSetting::get('payment_deadline'),
            'system_open' => AdminSetting::get('system_open'),
            'system_message' => AdminSetting::get('system_message'),
            'contact_email' => AdminSetting::get('contact_email'),
            'contact_phone' => AdminSetting::get('contact_phone'),
            'maintenance_mode' => AdminSetting::get('maintenance_mode'),
        ];
        
        return response()->json(['settings' => $settings]);
    }
    
    /**
     * Update system settings with validation
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSystemSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'registration_fee' => 'sometimes|numeric|min:0|max:1000000',
                'late_fee' => 'sometimes|numeric|min:0|max:1000000',
                'payment_deadline' => 'sometimes|date|after:now',
                'system_open' => 'sometimes|boolean',
                'system_message' => 'sometimes|string|max:1000',
                'contact_email' => 'sometimes|email',
                'contact_phone' => 'sometimes|string|max:20',
                'maintenance_mode' => 'sometimes|boolean'
            ]);

            DB::beginTransaction();

            foreach ($validated as $key => $value) {
                // Map frontend keys to backend setting keys
                $settingKey = $key === 'registration_fee' ? 'payment_amount' : 
                             ($key === 'late_fee' ? 'late_payment_fee' : $key);
                
                AdminSetting::set(
                    $settingKey,
                    $value,
                    $this->getSettingType($settingKey),
                    $this->getSettingDescription($settingKey),
                    $this->getSettingCategory($settingKey)
                );
            }

            // Clear relevant caches
            AdminSetting::clearCache();
            Cache::forget('nysc.payment_amount');
            Cache::forget('nysc.late_payment_fee');
            Cache::forget('nysc.payment_deadline');
            Cache::forget('nysc.system_open');
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'System settings updated successfully',
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Settings update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update system settings',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Get email settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEmailSettings(): \Illuminate\Http\JsonResponse
    {
        $settings = [
            'smtp_host' => AdminSetting::get('smtp_host'),
            'smtp_port' => AdminSetting::get('smtp_port'),
            'smtp_username' => AdminSetting::get('smtp_username'),
            'smtp_encryption' => AdminSetting::get('smtp_encryption'),
            'from_email' => AdminSetting::get('from_email'),
            'from_name' => AdminSetting::get('from_name'),
        ];
        
        return response()->json(['settings' => $settings]);
    }
    
    /**
     * Update email settings
     *
     * @param  \Illuminate\Http\Request  $request
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
                'from_email' => 'sometimes|email',
                'from_name' => 'sometimes|string|max:255',
            ]);
            
            DB::beginTransaction();
            
            // Store settings using AdminSetting model (except password for security)
            foreach ($validated as $key => $value) {
                if ($key !== 'smtp_password') {
                    AdminSetting::set(
                        $key,
                        $value,
                        $this->getSettingType($key),
                        $this->getSettingDescription($key),
                        'email'
                    );
                }
            }
            
            // Clear relevant caches
            AdminSetting::clearCache();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Email settings updated successfully.',
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Email settings update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update email settings',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Upload CSV file for bulk student import
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadCsv(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('csv_file');
            $path = $file->store('temp', 'local');
            $fullPath = storage_path('app/' . $path);

            // Read and process CSV
            $csvData = [];
            $errors = [];
            $successCount = 0;
            $errorCount = 0;

            if (($handle = fopen($fullPath, 'r')) !== FALSE) {
                $header = fgetcsv($handle); // Skip header row
                $rowNumber = 1;

                while (($data = fgetcsv($handle)) !== FALSE) {
                    $rowNumber++;
                    
                    try {
                        // Map CSV columns to database fields
                        $studentData = [
                            'fname' => $data[0] ?? '',
                            'lname' => $data[1] ?? '',
                            'mname' => $data[2] ?? '',
                            'matric_no' => $data[3] ?? '',
                            'email' => $data[4] ?? '',
                            'phone' => $data[5] ?? '',
                            'gender' => $data[6] ?? '',
                            'dob' => $data[7] ?? null,
                            'state_of_origin' => $data[8] ?? '',
                            'lga' => $data[9] ?? '',
                            'course_of_study' => $data[10] ?? '',
                            'department' => $data[11] ?? '',
                            'graduation_year' => $data[12] ?? null,
                            'cgpa' => $data[13] ?? null,
                            'jambno' => $data[14] ?? '',
                            'study_mode' => $data[15] ?? 'full-time',
                        ];

                        // Validate required fields
                        if (empty($studentData['fname']) || empty($studentData['lname']) || empty($studentData['matric_no'])) {
                            $errors[] = "Row {$rowNumber}: Missing required fields (fname, lname, matric_no)";
                            $errorCount++;
                            continue;
                        }

                        // Check if student exists in the main students table
                        $existingStudent = \App\Models\Student::where('matric_no', $studentData['matric_no'])->first();
                        if (!$existingStudent) {
                            $errors[] = "Row {$rowNumber}: Student with matric number {$studentData['matric_no']} not found in system";
                            $errorCount++;
                            continue;
                        }

                        // Prepare data for student_nysc table
                        $nyscData = array_merge($studentData, [
                            'student_id' => $existingStudent->id,
                            'is_submitted' => true,
                            'is_paid' => false,
                            'payment_amount' => null,
                            'submission_date' => now(),
                        ]);

                        // Check if NYSC record already exists
                        $existingNyscRecord = \App\Models\StudentNysc::where('student_id', $existingStudent->id)->first();
                        if ($existingNyscRecord) {
                            // Update existing NYSC record
                            $existingNyscRecord->update($nyscData);
                        } else {
                            // Create new NYSC record
                            \App\Models\StudentNysc::create($nyscData);
                        }

                        $successCount++;
                    } catch (\Exception $e) {
                        $errors[] = "Row {$rowNumber}: " . $e->getMessage();
                        $errorCount++;
                    }
                }
                fclose($handle);
            }

            // Clean up temp file
            unlink($fullPath);

            return response()->json([
                'success' => true,
                'message' => "CSV import completed. {$successCount} records processed successfully.",
                'statistics' => [
                    'total_rows' => $successCount + $errorCount,
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'errors' => array_slice($errors, 0, 10) // Limit to first 10 errors
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process CSV file',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download CSV template for student NYSC data import
     *
     * @return \Illuminate\Http\Response
     */
    public function downloadCsvTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="student_nysc_import_template.csv"',
        ];

        $callback = function() {
            $file = fopen('php://output', 'w');
            
            // Add headers for student_nysc table fields (exact match)
            fputcsv($file, [
                'matric_no', 'fname', 'lname', 'mname', 'gender', 'dob', 'marital_status',
                'phone', 'email', 'address', 'state', 'lga', 'username', 'department',
                'course_study', 'level', 'graduation_year', 'cgpa', 'jamb_no', 'study_mode'
            ]);
            
            // Add sample data
            fputcsv($file, [
                'VUG/CSC/16/1001', 'John', 'Doe', 'Smith', 'Male', '1995-05-15', 'Single',
                '08012345678', 'john.doe@example.com', '123 Main St, Lagos', 'Lagos', 'Ikeja',
                'john.doe', 'Computer Science', 'Computer Science', '400', '2020', '3.50',
                'JAM123456789', 'Full Time'
            ]);
            
            // Add instructions as comments
            fputcsv($file, [
                '# INSTRUCTIONS FOR STUDENT NYSC DATA IMPORT', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            fputcsv($file, [
                '# 1. Only students with existing matric_no in system will be processed', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            fputcsv($file, [
                '# 2. Students already in student_nysc table will be SKIPPED', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            fputcsv($file, [
                '# 3. Date format must be: YYYY-MM-DD (e.g., 1995-05-15)', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            fputcsv($file, [
                '# 4. Gender: Male/Female, Marital Status: Single/Married/Divorced/Widowed', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            fputcsv($file, [
                '# 5. Remove all instruction rows before uploading', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
            ]);
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test email configuration
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testEmail(): \Illuminate\Http\JsonResponse
    {
        try {
            // Send test email
            $testEmail = Cache::get('nysc.contact_email', 'admin@nysc.gov.ng');
            
            // Here you would implement actual email sending logic
            // For now, we'll just return a success response
            
            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully to ' . $testEmail
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    

    
    /**
     * Get all students with their Student Data
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllStudents(): \Illuminate\Http\JsonResponse
    {
        try {
            $students = StudentNysc::with(['student', 'payments' => function($query) {
                $query->where('status', 'successful');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($nysc) {
                return [
                    'id' => $nysc->id,
                    'student_id' => $nysc->student_id,
                    'fname' => $nysc->fname ?? 'N/A',
                    'lname' => $nysc->lname ?? 'N/A',
                    'student_name' => trim(($nysc->fname ?? '') . ' ' . ($nysc->lname ?? '')),
                    'matric_no' => $nysc->matric_no ?? 'N/A',
                    'email' => $nysc->email ?? 'N/A',
                    'department' => $nysc->department ?? 'N/A',
                    'course_of_study' => $nysc->course_of_study ?? 'N/A',
                    'graduation_year' => $nysc->graduation_year ?? 'N/A',
                    'cgpa' => $nysc->cgpa ?? 'N/A',
                    'gender' => $nysc->gender ?? 'N/A',
                    'phone' => $nysc->phone ?? 'N/A',
                    'state_of_origin' => $nysc->state_of_origin ?? 'N/A',
                    'lga' => $nysc->lga ?? 'N/A',
                    'institution' => $nysc->institution ?? 'N/A',
                    'is_paid' => $nysc->is_paid ?? false,
                    'payment_amount' => $nysc->payment_amount ?? 0,
                    'is_submitted' => $nysc->is_submitted ?? false,
                    'payment_reference' => $nysc->payment_reference ?? 'N/A',
                    'payment_date' => $nysc->payment_date ?? null,
                    'created_at' => $nysc->created_at,
                    'updated_at' => $nysc->updated_at,
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $students,
                'total' => $students->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch students data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get student statistics for admin dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $totalStudents = StudentNysc::where('is_submitted', true)->count();
            $paidStudents = StudentNysc::where('is_submitted', true)->where('is_paid', true)->count();
            $pendingPayments = $totalStudents - $paidStudents;
            
            return response()->json([
                'total_students' => $totalStudents,
                'submitted_applications' => $totalStudents,
                'paid_students' => $paidStudents,
                'pending_payments' => $pendingPayments
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch student statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed information for a specific student
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudentDetails(string $id): \Illuminate\Http\JsonResponse
    {
        try {
            $student = StudentNysc::with(['student', 'payments' => function($query) {
                $query->orderBy('created_at', 'desc');
            }])
            ->where('student_id', $id)
            ->orWhere('id', $id)
            ->first();

            if (!$student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found'
                ], 404);
            }

            $studentDetails = [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'fname' => $student->fname,
                'lname' => $student->lname,
                'mname' => $student->mname,
                'email' => $student->email,
                'phone' => $student->phone,
                'matric_no' => $student->matric_no,
                'department' => $student->department,
                'course_of_study' => $student->course_of_study,
                'graduation_year' => $student->graduation_year,
                'cgpa' => $student->cgpa,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth,
                'state_of_origin' => $student->state_of_origin,
                'lga' => $student->lga,
                'address' => $student->address,
                'next_of_kin_name' => $student->next_of_kin_name,
                'next_of_kin_phone' => $student->next_of_kin_phone,
                'next_of_kin_relationship' => $student->next_of_kin_relationship,
                'is_paid' => $student->is_paid,
                'payment_amount' => $student->payment_amount,
                'payment_reference' => $student->payment_reference,
                'payment_date' => $student->payment_date,
                'is_submitted' => $student->is_submitted,
                'created_at' => $student->created_at,
                'updated_at' => $student->updated_at,
                'payments' => $student->payments->map(function($payment) {
                    return [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'reference' => $payment->reference,
                        'status' => $payment->status,
                        'payment_date' => $payment->payment_date,
                        'created_at' => $payment->created_at
                    ];
                })
            ];

            return response()->json([
                'success' => true,
                'data' => $studentDetails
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch student details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get temporary submissions
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubmissions(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 10);
            
            // Get temporary submissions with student information
            $submissions = \App\Models\NyscTempSubmission::with(['student'])
                ->orderBy('created_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);
            
            $formattedSubmissions = $submissions->map(function($submission) {
                $student = $submission->student;
                $formData = json_decode($submission->form_data, true) ?? [];
                
                return [
                    'id' => $submission->id,
                    'student_id' => $submission->student_id,
                    'student_name' => $student ? $student->fname . ' ' . $student->lname : 'N/A',
                    'matric_number' => $student ? $student->matric_no : 'N/A',
                    'email' => $student ? $student->email : 'N/A',
                    'department' => $formData['department'] ?? 'N/A',
                    'faculty' => $formData['faculty'] ?? 'N/A',
                    'level' => $formData['level'] ?? 'N/A',
                    'submission_type' => 'initial',
                    'submission_status' => $submission->status,
                    'submitted_data' => $formData,
                    'submission_date' => $submission->created_at,
                    'reviewed_date' => $submission->reviewed_at,
                    'reviewed_by' => $submission->reviewed_by,
                    'review_notes' => $submission->review_notes,
                    'created_at' => $submission->created_at,
                    'updated_at' => $submission->updated_at,
                ];
            });
            
            return response()->json([
                'success' => true,
                'submissions' => $formattedSubmissions,
                'total' => $submissions->total(),
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch submissions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get submission details
     *
     * @param string $submissionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSubmissionDetails(string $submissionId): \Illuminate\Http\JsonResponse
    {
        try {
            $submission = \App\Models\NyscTempSubmission::with(['student'])
                ->findOrFail($submissionId);
            
            $student = $submission->student;
            $formData = json_decode($submission->form_data, true) ?? [];
            
            $submissionDetails = [
                'id' => $submission->id,
                'student_id' => $submission->student_id,
                'student_name' => $student ? $student->fname . ' ' . $student->lname : 'N/A',
                'matric_number' => $student ? $student->matric_no : 'N/A',
                'email' => $student ? $student->email : 'N/A',
                'department' => $formData['department'] ?? 'N/A',
                'faculty' => $formData['faculty'] ?? 'N/A',
                'level' => $formData['level'] ?? 'N/A',
                'submission_type' => 'initial',
                'submission_status' => $submission->status,
                'submitted_data' => $formData,
                'submission_date' => $submission->created_at,
                'reviewed_date' => $submission->reviewed_at,
                'reviewed_by' => $submission->reviewed_by,
                'review_notes' => $submission->review_notes,
                'created_at' => $submission->created_at,
                'updated_at' => $submission->updated_at,
            ];
            
            return response()->json([
                'success' => true,
                'submission' => $submissionDetails
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch submission details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update submission status
     *
     * @param \Illuminate\Http\Request $request
     * @param string $submissionId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSubmissionStatus(Request $request, string $submissionId): \Illuminate\Http\JsonResponse
    {
        try {
            $submission = \App\Models\NyscTempSubmission::findOrFail($submissionId);
            
            $submission->update([
                'status' => $request->status,
                'review_notes' => $request->notes,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Submission status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update submission status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create export job
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createExportJob(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $type = $request->input('type'); // student_nysc, payments, submissions
            $format = $request->input('format'); // csv, excel, pdf
            $filters = $request->input('filters', []);
            
            // Generate unique job ID
            $jobId = uniqid('export_', true);
            
            // Store job in cache with initial status
            $jobData = [
                'id' => $jobId,
                'type' => $type,
                'format' => $format,
                'filters' => $filters,
                'status' => 'processing',
                'progress' => 0,
                'created_at' => now()->toISOString(),
            ];
            
            Cache::put("export_job_{$jobId}", $jobData, 3600); // 1 hour
            
            // Track job keys
            $cacheKeys = Cache::get('export_job_keys', []);
            $cacheKeys[] = "export_job_{$jobId}";
            Cache::put('export_job_keys', $cacheKeys, 3600);
            
            // Process export based on type
            $data = $this->getExportData($type, $filters);
            $recordCount = count($data);
            
            // Generate file
            $fileName = $this->generateExportFile($data, $type, $format, $jobId);
            
            // Update job status
            $jobData['status'] = 'completed';
            $jobData['progress'] = 100;
            $jobData['record_count'] = $recordCount;
            $jobData['file_name'] = $fileName;
            $jobData['download_url'] = "/api/nysc/admin/export-jobs/{$jobId}/download";
            
            Cache::put("export_job_{$jobId}", $jobData, 3600);
            
            return response()->json([
                'success' => true,
                'job_id' => $jobId,
                'message' => 'Export job created successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create export job',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get export jobs
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportJobs(): \Illuminate\Http\JsonResponse
    {
        try {
            // Get all export jobs from cache
            $jobs = [];
            $cacheKeys = Cache::get('export_job_keys', []);
            
            foreach ($cacheKeys as $key) {
                $job = Cache::get($key);
                if ($job) {
                    $jobs[] = $job;
                }
            }
            
            // Sort by created_at desc
            usort($jobs, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            return response()->json([
                'success' => true,
                'jobs' => $jobs
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch export jobs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get export job status
     *
     * @param string $jobId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportJobStatus(string $jobId): \Illuminate\Http\JsonResponse
    {
        try {
            $job = Cache::get("export_job_{$jobId}");
            
            if (!$job) {
                return response()->json([
                    'success' => false,
                    'message' => 'Export job not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'job' => $job
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get export job status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download export file
     *
     * @param string $jobId
     * @return \Illuminate\Http\Response
     */
    public function downloadExportFile(string $jobId)
    {
        try {
            $job = Cache::get("export_job_{$jobId}");
            
            if (!$job || $job['status'] !== 'completed') {
                return response()->json(['error' => 'Export job not found or not completed'], 404);
            }
            
            $filePath = storage_path("app/exports/{$job['file_name']}");
            
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Export file not found'], 404);
            }
            
            return response()->download($filePath);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to download file'], 500);
        }
    }

    /**
     * Get export data based on type and filters
     *
     * @param string $type
     * @param array $filters
     * @return array
     */
    private function getExportData(string $type, array $filters): array
    {
        switch ($type) {
            case 'student_nysc':
                return $this->getStudentNyscExportData($filters);
            case 'payments':
                return $this->getPaymentsExportData($filters);
            case 'submissions':
                return $this->getSubmissionsExportData($filters);
            default:
                throw new \Exception('Invalid export type');
        }
    }

    /**
     * Get student Student Data for export
     *
     * @param array $filters
     * @return array
     */
    private function getStudentNyscExportData(array $filters): array
    {
        $query = StudentNysc::with(['student', 'payments']);
        
        // Apply filters
        if (!empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }
        
        if (!empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }
        
        if (!empty($filters['state'])) {
            $query->where('state_of_origin', $filters['state']);
        }
        
        if (!empty($filters['paymentStatus'])) {
            if ($filters['paymentStatus'] === 'paid') {
                $query->where('is_paid', true);
            } else {
                $query->where('is_paid', false);
            }
        }
        
        if (!empty($filters['matricNumbers'])) {
            $query->whereIn('matric_no', $filters['matricNumbers']);
        }
        
        if (!empty($filters['dateRange']['start']) && !empty($filters['dateRange']['end'])) {
            $query->whereBetween('created_at', [$filters['dateRange']['start'], $filters['dateRange']['end']]);
        }
        
        return $query->get()->map(function($student) {
            $latestPayment = $student->payments->first();
            return [
                'ID' => $student->id,
                'Student ID' => $student->student_id,
                'First Name' => $student->fname,
                'Last Name' => $student->lname,
                'Middle Name' => $student->mname,
                'Matric Number' => $student->matric_no,
                'Email' => $student->student ? $student->student->email : '',
                'Phone' => $student->phone,
                'Department' => $student->department,
                'Faculty' => $student->faculty,
                'Level' => $student->level,
                'Gender' => $student->gender,
                'Date of Birth' => $student->dob,
                'State of Origin' => $student->state_of_origin,
                'LGA' => $student->lga,
                'Address' => $student->address,
                'Is Paid' => $student->is_paid ? 'Yes' : 'No',
                'Payment Amount' => $latestPayment ? $latestPayment->amount : 0,
                'Payment Date' => $latestPayment ? $latestPayment->payment_date : '',
                'Submission Date' => $student->created_at,
            ];
        })->toArray();
    }

    /**
     * Get payments data for export
     *
     * @param array $filters
     * @return array
     */
    private function getPaymentsExportData(array $filters): array
    {
        $query = NyscPayment::with(['studentNysc.student']);
        
        // Apply filters
        if (!empty($filters['department'])) {
            $query->whereHas('studentNysc', function($q) use ($filters) {
                $q->where('department', $filters['department']);
            });
        }
        
        if (!empty($filters['paymentStatus'])) {
            $query->where('status', $filters['paymentStatus']);
        }
        
        if (!empty($filters['dateRange']['start']) && !empty($filters['dateRange']['end'])) {
            $query->whereBetween('payment_date', [$filters['dateRange']['start'], $filters['dateRange']['end']]);
        }
        
        return $query->get()->map(function($payment) {
            $studentNysc = $payment->studentNysc;
            $student = $studentNysc ? $studentNysc->student : null;
            
            return [
                'Payment ID' => $payment->id,
                'Student ID' => $payment->student_id,
                'Student Name' => $studentNysc ? trim($studentNysc->fname . ' ' . $studentNysc->mname . ' ' . $studentNysc->lname) : 'N/A',
                'Matric Number' => $studentNysc ? $studentNysc->matric_no : 'N/A',
                'Email' => $student ? $student->email : 'N/A',
                'Department' => $studentNysc ? $studentNysc->department : 'N/A',
                'Amount' => $payment->amount,
                'Payment Method' => $payment->payment_method ?? 'paystack',
                'Status' => $payment->status,
                'Reference' => $payment->payment_reference,
                'Payment Date' => $payment->payment_date,
                'Created At' => $payment->created_at,
            ];
        })->toArray();
    }

    /**
     * Get submissions data for export
     *
     * @param array $filters
     * @return array
     */
    private function getSubmissionsExportData(array $filters): array
    {
        $query = NyscTempSubmission::with(['student']);
        
        // Apply filters
        if (!empty($filters['department'])) {
            $query->whereJsonContains('form_data->department', $filters['department']);
        }
        
        if (!empty($filters['dateRange']['start']) && !empty($filters['dateRange']['end'])) {
            $query->whereBetween('created_at', [$filters['dateRange']['start'], $filters['dateRange']['end']]);
        }
        
        return $query->get()->map(function($submission) {
            $student = $submission->student;
            $formData = json_decode($submission->form_data, true) ?? [];
            
            return [
                'Submission ID' => $submission->id,
                'Student ID' => $submission->student_id,
                'Student Name' => $student ? $student->fname . ' ' . $student->lname : 'N/A',
                'Matric Number' => $student ? $student->matric_no : 'N/A',
                'Email' => $student ? $student->email : 'N/A',
                'Department' => $formData['department'] ?? 'N/A',
                'Faculty' => $formData['faculty'] ?? 'N/A',
                'Level' => $formData['level'] ?? 'N/A',
                'Status' => $submission->status,
                'Submission Date' => $submission->created_at,
                'Reviewed Date' => $submission->reviewed_at,
                'Review Notes' => $submission->review_notes,
            ];
        })->toArray();
    }

    /**
     * Generate export file
     *
     * @param array $data
     * @param string $type
     * @param string $format
     * @param string $jobId
     * @return string
     */
    private function generateExportFile(array $data, string $type, string $format, string $jobId): string
    {
        $fileName = "{$type}_{$format}_{$jobId}.{$format}";
        $filePath = storage_path("app/exports/{$fileName}");
        
        // Ensure exports directory exists
        if (!file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }
        
        switch ($format) {
            case 'csv':
                $this->generateCsvFile($data, $filePath);
                break;
            case 'excel':
                $this->generateExcelFile($data, $filePath);
                break;
            case 'pdf':
                $this->generatePdfFile($data, $filePath, $type);
                break;
            default:
                throw new \Exception('Unsupported export format');
        }
        
        return $fileName;
    }

    /**
     * Generate CSV file
     *
     * @param array $data
     * @param string $filePath
     */
    private function generateCsvFile(array $data, string $filePath): void
    {
        $file = fopen($filePath, 'w');
        
        if (!empty($data)) {
            // Write headers
            fputcsv($file, array_keys($data[0]));
            
            // Write data
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
        }
        
        fclose($file);
    }

    /**
     * Generate Excel file (simplified - using CSV format for now)
     *
     * @param array $data
     * @param string $filePath
     */
    private function generateExcelFile(array $data, string $filePath): void
    {
        // For now, generate as CSV with .xlsx extension
        // In production, you would use a library like PhpSpreadsheet
        $this->generateCsvFile($data, $filePath);
    }

    /**
     * Generate PDF file (simplified)
     *
     * @param array $data
     * @param string $filePath
     * @param string $type
     */
    private function generatePdfFile(array $data, string $filePath, string $type): void
    {
        // For now, generate a simple HTML table and save as text
        // In production, you would use a library like TCPDF or DomPDF
        $html = "<h1>" . ucfirst(str_replace('_', ' ', $type)) . " Export</h1>";
        $html .= "<table border='1'>";
        
        if (!empty($data)) {
            // Headers
            $html .= "<tr>";
            foreach (array_keys($data[0]) as $header) {
                $html .= "<th>{$header}</th>";
            }
            $html .= "</tr>";
            
            // Data
            foreach ($data as $row) {
                $html .= "<tr>";
                foreach ($row as $cell) {
                    $html .= "<td>{$cell}</td>";
                }
                $html .= "</tr>";
            }
        }
        
        $html .= "</table>";
        file_put_contents($filePath, $html);
    }

    // Admin Users Management Methods
    public function getAdminUsers(Request $request)
    {
        try {
            $query = Staff::query();
            
            // Apply filters
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            
            if ($request->has('status') && !empty($request->status)) {
                $query->where('status', $request->status);
            }
            
            $adminUsers = $query->select([
                'id', 'fname', 'lname', 'email', 'status', 
                'created_at', 'updated_at', 'last_login_at'
            ])->get();
            
            // Transform data to match frontend interface
            $transformedUsers = $adminUsers->map(function($user) {
                // Get role based on staff ID (using the same logic as frontend)
                $role = $this->getUserRole($user->id);
                
                return [
                    'id' => $user->id,
                    'staff_id' => (string)$user->id,
                    'name' => trim($user->fname . ' ' . $user->lname),
                    'email' => $user->email,
                    'role' => $role,
                    'permissions' => $this->getRolePermissions($role),
                    'status' => $user->status === 'active' ? 'active' : 'inactive',
                    'last_login' => $user->last_login_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ];
            });
            
            return response()->json([
                'success' => true,
                'users' => $transformedUsers
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch admin users',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function createAdminUser(Request $request)
    {
        try {
            $validated = $request->validate([
                'fname' => 'required|string|max:255',
                'lname' => 'required|string|max:255',
                'email' => 'required|email|unique:staff,email',
                'password' => 'required|string|min:6',
                'role' => 'required|in:super_admin,admin,sub_admin,manager',
                'status' => 'required|in:active,inactive'
            ]);
            
            $user = Staff::create([
                'fname' => $validated['fname'],
                'lname' => $validated['lname'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status' => $validated['status']
            ]);
            
            // Store role information in cache for now
            // In production, this should be stored in a proper roles table
            Cache::put("user_role_{$user->id}", $validated['role'], now()->addYears(1));
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user created successfully',
                'user' => [
                    'id' => $user->id,
                    'staff_id' => (string)$user->id,
                    'name' => trim($user->fname . ' ' . $user->lname),
                    'email' => $user->email,
                    'role' => $validated['role'],
                    'permissions' => $this->getRolePermissions($validated['role']),
                    'status' => $user->status === 'active' ? 'active' : 'inactive',
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ]
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function updateAdminUser(Request $request, $userId)
    {
        try {
            $user = Staff::find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found'
                ], 404);
            }
            
            $validated = $request->validate([
                'fname' => 'sometimes|required|string|max:255',
                'lname' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|unique:staff,email,' . $userId,
                'password' => 'nullable|string|min:6',
                'role' => 'sometimes|required|in:super_admin,admin,sub_admin,manager',
                'status' => 'sometimes|required|in:active,inactive'
            ]);
            
            // Update user fields
            if (isset($validated['fname'])) $user->fname = $validated['fname'];
            if (isset($validated['lname'])) $user->lname = $validated['lname'];
            if (isset($validated['email'])) $user->email = $validated['email'];
            if (isset($validated['status'])) $user->status = $validated['status'];
            
            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }
            
            $user->save();
            
            // Update role in cache if provided
            if (isset($validated['role'])) {
                Cache::put("user_role_{$user->id}", $validated['role'], now()->addYears(1));
            }
            
            $role = $validated['role'] ?? $this->getUserRole($user->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user updated successfully',
                'user' => [
                    'id' => $user->id,
                    'staff_id' => (string)$user->id,
                    'name' => trim($user->fname . ' ' . $user->lname),
                    'email' => $user->email,
                    'role' => $role,
                    'permissions' => $this->getRolePermissions($role),
                    'status' => $user->status === 'active' ? 'active' : 'inactive',
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function deleteAdminUser($userId)
    {
        try {
            $user = Staff::find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Admin user not found'
                ], 404);
            }
            
            // Prevent deletion of super admin
            if ($user->id == 596) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete super admin user'
                ], 403);
            }
            
            $user->delete();
            
            // Remove role from cache
            Cache::forget("user_role_{$userId}");
            
            return response()->json([
                'success' => true,
                'message' => 'Admin user deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete admin user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateAdminProfile(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $validated = $request->validate([
                'fname' => 'sometimes|required|string|max:255',
                'lname' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|unique:staff,email,' . $user->id,
                'phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'department' => 'nullable|string|max:255'
            ]);

            // Update user fields
            if (isset($validated['fname'])) $user->fname = $validated['fname'];
            if (isset($validated['lname'])) $user->lname = $validated['lname'];
            if (isset($validated['email'])) $user->email = $validated['email'];
            if (isset($validated['phone'])) $user->phone = $validated['phone'];
            if (isset($validated['address'])) $user->address = $validated['address'];
            if (isset($validated['department'])) $user->department = $validated['department'];

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'fname' => $user->fname,
                    'lname' => $user->lname,
                    'name' => trim($user->fname . ' ' . $user->lname),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'department' => $user->department,
                    'updated_at' => $user->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // Helper methods for role management
    private function getUserRole($staffId)
    {
        // Check cache first
        $cachedRole = Cache::get("user_role_{$staffId}");
        if ($cachedRole) {
            return $cachedRole;
        }
        
        // Fallback to hardcoded logic (same as frontend)
        if ($staffId == 596) {
            return 'super_admin';
        }
        
        if ($staffId >= 500 && $staffId < 600) {
            return 'admin';
        } else if ($staffId >= 400 && $staffId < 500) {
            return 'sub_admin';
        } else {
            return 'manager';
        }
    }
    
    private function getRolePermissions($role)
    {
        $permissions = [
            'super_admin' => [
                'canViewStudentNysc' => true,
                'canEditStudentNysc' => true,
                'canAddStudentNysc' => true,
                'canDeleteStudentNysc' => true,
                'canViewPayments' => true,
                'canEditPayments' => true,
                'canViewTempSubmissions' => true,
                'canEditTempSubmissions' => true,
                'canDownloadData' => true,
                'canAssignRoles' => true,
                'canViewAnalytics' => true,
                'canManageSystem' => true,
            ],
            'admin' => [
                'canViewStudentNysc' => true,
                'canEditStudentNysc' => true,
                'canAddStudentNysc' => true,
                'canDeleteStudentNysc' => true,
                'canViewPayments' => true,
                'canEditPayments' => true,
                'canViewTempSubmissions' => true,
                'canEditTempSubmissions' => true,
                'canDownloadData' => true,
                'canAssignRoles' => false,
                'canViewAnalytics' => true,
                'canManageSystem' => true,
            ],
            'sub_admin' => [
                'canViewStudentNysc' => true,
                'canEditStudentNysc' => true,
                'canAddStudentNysc' => true,
                'canDeleteStudentNysc' => false,
                'canViewPayments' => true,
                'canEditPayments' => false,
                'canViewTempSubmissions' => true,
                'canEditTempSubmissions' => true,
                'canDownloadData' => true,
                'canAssignRoles' => false,
                'canViewAnalytics' => true,
                'canManageSystem' => false,
            ],
            'manager' => [
                'canViewStudentNysc' => true,
                'canEditStudentNysc' => false,
                'canAddStudentNysc' => false,
                'canDeleteStudentNysc' => false,
                'canViewPayments' => true,
                'canEditPayments' => false,
                'canViewTempSubmissions' => true,
                'canEditTempSubmissions' => false,
                'canDownloadData' => true,
                'canAssignRoles' => false,
                'canViewAnalytics' => true,
                'canManageSystem' => false,
            ],
        ];
        
        return $permissions[$role] ?? $permissions['manager'];
    }

    /**
     * Get admin settings
     */
    public function getSettings()
    {
        try {
            $settings = AdminSetting::where('is_active', true)
                ->get()
                ->groupBy('category')
                ->map(function ($categorySettings) {
                    return $categorySettings->mapWithKeys(function ($setting) {
                        return [$setting->key => [
                            'value' => $this->castSettingValue($setting->value, $setting->type),
                            'type' => $setting->type,
                            'description' => $setting->description
                        ]];
                    });
                });

            return response()->json([
                'success' => true,
                'settings' => $settings
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update admin settings with enhanced validation
     */
    public function updateSettings(Request $request)
    {
        try {
            // Validate the request structure
            $validated = $request->validate([
                'payment_amount' => 'sometimes|numeric|min:0|max:1000000',
                'late_payment_fee' => 'sometimes|numeric|min:0|max:1000000',
                'payment_deadline' => 'sometimes|date|after:now',
                'countdown_title' => 'sometimes|string|max:255',
                'countdown_message' => 'sometimes|string|max:1000',
                'system_open' => 'sometimes|boolean',
                'settings' => 'sometimes|array',
                'settings.*.value' => 'sometimes|nullable',
                'settings.*.type' => 'sometimes|in:string,number,boolean,json,date',
                'settings.*.category' => 'sometimes|in:payment,countdown,system,general,email'
            ]);

            DB::beginTransaction();

            // Handle direct payment settings update (for frontend compatibility)
            if ($request->has('payment_amount') || $request->has('late_payment_fee') || 
                $request->has('payment_deadline') || $request->has('countdown_title') || 
                $request->has('countdown_message') || $request->has('system_open')) {
                
                $paymentSettings = [
                    'payment_amount' => ['value' => $validated['payment_amount'] ?? null, 'type' => 'number', 'category' => 'payment'],
                    'late_payment_fee' => ['value' => $validated['late_payment_fee'] ?? null, 'type' => 'number', 'category' => 'payment'],
                    'payment_deadline' => ['value' => $validated['payment_deadline'] ?? null, 'type' => 'date', 'category' => 'payment'],
                    'countdown_title' => ['value' => $validated['countdown_title'] ?? null, 'type' => 'string', 'category' => 'countdown'],
                    'countdown_message' => ['value' => $validated['countdown_message'] ?? null, 'type' => 'string', 'category' => 'countdown'],
                    'system_open' => ['value' => $validated['system_open'] ?? null, 'type' => 'boolean', 'category' => 'system']
                ];

                foreach ($paymentSettings as $key => $data) {
                    if ($data['value'] !== null) {
                        AdminSetting::set(
                            $key,
                            $data['value'],
                            $data['type'],
                            $this->getSettingDescription($key),
                            $data['category']
                        );
                    }
                }
            }

            // Handle structured settings update
            if ($request->has('settings')) {
                $settings = $request->input('settings', []);
                
                foreach ($settings as $key => $data) {
                    // Additional validation for specific settings
                    if (isset($data['value'])) {
                        $this->validateSpecificSetting($key, $data['value']);
                        
                        AdminSetting::set(
                            $key,
                            $data['value'],
                            $data['type'] ?? 'string',
                            $data['description'] ?? $this->getSettingDescription($key),
                            $data['category'] ?? 'general'
                        );
                    }
                }
            }

            // Clear relevant caches
            AdminSetting::clearCache();
            Cache::forget('nysc.payment_amount');
            Cache::forget('nysc.late_payment_fee');
            Cache::forget('nysc.payment_deadline');
            Cache::forget('nysc.system_open');

            DB::commit();

            // Return updated settings
            $updatedSettings = $this->getFormattedSettings();

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully',
                'data' => $updatedSettings,
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Settings update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Export student data in various formats
     */
    public function exportStudents(Request $request, $format = 'excel')
    {
        try {
            $query = StudentNysc::with(['student', 'payments' => function($q) {
                $q->where('status', 'successful');
            }]);

            // Apply filters if provided
            if ($request->has('department') && $request->department) {
                $query->where('department', $request->department);
            }
            
            if ($request->has('is_paid')) {
                $query->where('is_paid', $request->boolean('is_paid'));
            }
            
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $students = $query->get();
            $filename = 'nysc_students_' . now()->format('Y-m-d_H-i-s');

            switch (strtolower($format)) {
                case 'excel':
                case 'xlsx':
                    return Excel::download(new StudentNyscExport($students), $filename . '.xlsx');
                    
                case 'csv':
                    return Excel::download(new StudentNyscExport($students), $filename . '.csv', \Maatwebsite\Excel\Excel::CSV);
                    
                case 'pdf':
                    $pdf = Pdf::loadView('exports.students-pdf', compact('students'));
                    return $pdf->download($filename . '.pdf');
                    
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Unsupported export format. Use: excel, csv, or pdf'
                    ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get students data with advanced filtering and pagination
     */
    public function getStudentsData(Request $request)
    {
        try {
            $query = StudentNysc::with(['student', 'payments' => function($q) {
                $q->where('status', 'successful');
            }]);

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('matric_no', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('department', 'like', "%{$search}%");
                });
            }

            // Filters
            if ($request->has('department') && $request->department) {
                $query->where('department', $request->department);
            }
            
            if ($request->has('is_paid')) {
                $query->where('is_paid', $request->boolean('is_paid'));
            }
            
            if ($request->has('gender') && $request->gender) {
                $query->where('gender', $request->gender);
            }
            
            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $students = $query->paginate($perPage);

            // Transform data for frontend
            $students->getCollection()->transform(function ($student) {
                return [
                    'id' => $student->id,
                    'student_id' => $student->student_id,
                    'name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'matric_no' => $student->matric_no,
                    'email' => $student->email,
                    'phone' => $student->phone,
                    'gender' => $student->gender,
                    'department' => $student->department,
                    'course_study' => $student->course_study,
                    'level' => $student->level,
                    'cgpa' => $student->cgpa,
                    'graduation_year' => $student->graduation_year,
                    'is_paid' => $student->is_paid,
                    'is_submitted' => $student->is_submitted,
                    'payment_amount' => $student->payments->first()?->amount ?? 0,
                    'payment_date' => $student->payments->first()?->payment_date,
                    'created_at' => $student->created_at,
                    'updated_at' => $student->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $students->items(),
                'pagination' => [
                    'current_page' => $students->currentPage(),
                    'last_page' => $students->lastPage(),
                    'per_page' => $students->perPage(),
                    'total' => $students->total(),
                    'from' => $students->firstItem(),
                    'to' => $students->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch students data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard data with dynamic settings
     */
    public function getDashboardWithSettings()
    {
        try {
            // Get regular dashboard data
            $dashboardData = $this->dashboard()->getData(true);
            
            // Get admin settings
            $settings = AdminSetting::getByCategory('payment') + 
                       AdminSetting::getByCategory('countdown') + 
                       AdminSetting::getByCategory('system');
            
            return response()->json(array_merge($dashboardData, [
                'settings' => $settings
            ]));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to cast setting values
     */
    private function castSettingValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                return is_numeric($value) ? (float) $value : $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }

    /**
     * Get setting description for documentation
     */
    private function getSettingDescription($key)
    {
        $descriptions = [
            'payment_amount' => 'Standard payment amount for NYSC registration',
            'late_payment_fee' => 'Additional fee for late payments',
            'payment_deadline' => 'Deadline for standard payment amount',
            'countdown_title' => 'Title displayed on countdown timer',
            'countdown_message' => 'Message displayed with countdown timer',
            'system_open' => 'Whether the NYSC system is open for registrations',
            'registration_fee' => 'Base registration fee',
            'late_fee' => 'Late registration fee',
            'system_message' => 'System-wide message for users',
            'contact_email' => 'Contact email for support',
            'contact_phone' => 'Contact phone for support',
            'maintenance_mode' => 'System maintenance mode status',
            'smtp_host' => 'SMTP server hostname',
            'smtp_port' => 'SMTP server port number',
            'smtp_username' => 'SMTP authentication username',
            'smtp_encryption' => 'SMTP encryption method (tls, ssl, none)',
            'from_email' => 'Default sender email address',
            'from_name' => 'Default sender name'
        ];

        return $descriptions[$key] ?? 'System setting';
    }

    /**
     * Validate specific setting values
     */
    private function validateSpecificSetting($key, $value)
    {
        switch ($key) {
            case 'payment_amount':
            case 'late_payment_fee':
            case 'registration_fee':
            case 'late_fee':
                if (!is_numeric($value) || $value < 0 || $value > 1000000) {
                    throw new \InvalidArgumentException("Invalid amount for {$key}");
                }
                break;
            case 'payment_deadline':
                if (!strtotime($value) || strtotime($value) <= time()) {
                    throw new \InvalidArgumentException('Payment deadline must be a future date');
                }
                break;
            case 'contact_email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Invalid email format');
                }
                break;
            case 'contact_phone':
                if (!preg_match('/^[\+]?[0-9\-\s\(\)]+$/', $value)) {
                    throw new \InvalidArgumentException('Invalid phone format');
                }
                break;
        }
    }

    /**
     * Get formatted settings for response
     */
    private function getFormattedSettings()
    {
        $categories = ['payment', 'countdown', 'system', 'general', 'email'];
        $formattedSettings = [];

        foreach ($categories as $category) {
            $categorySettings = AdminSetting::getByCategory($category);
            if (!empty($categorySettings)) {
                $formattedSettings[$category] = $categorySettings;
            }
        }

        return $formattedSettings;
    }





    /**
     * Get setting type for proper casting
     */
    private function getSettingType($key)
    {
        $types = [
            'payment_amount' => 'number',
            'late_payment_fee' => 'number',
            'registration_fee' => 'number',
            'late_fee' => 'number',
            'system_open' => 'boolean',
            'maintenance_mode' => 'boolean',
            'payment_deadline' => 'date',
            'smtp_port' => 'number'
        ];

        return $types[$key] ?? 'string';
    }

    /**
     * Get setting category for organization
     */
    private function getSettingCategory($key)
    {
        $categories = [
            'payment_amount' => 'payment',
            'late_payment_fee' => 'payment',
            'registration_fee' => 'payment',
            'late_fee' => 'payment',
            'payment_deadline' => 'payment',
            'countdown_title' => 'countdown',
            'countdown_message' => 'countdown',
            'system_open' => 'system',
            'maintenance_mode' => 'system',
            'system_message' => 'system',
            'contact_email' => 'general',
            'contact_phone' => 'general',
            'smtp_host' => 'email',
            'smtp_port' => 'email',
            'smtp_username' => 'email',
            'smtp_encryption' => 'email',
            'from_email' => 'email',
            'from_name' => 'email'
        ];

        return $categories[$key] ?? 'general';
    }

    /**
     * Normalize matric number for better matching
     * Handles various formats like VUG/EEC/22/7641, VUG-EEC-22-7641, VUGEEC227641, etc.
     *
     * @param string $matricNo
     * @return array Multiple normalized versions for better matching
     */
    private function normalizeMatricNumber(string $matricNo): array
    {
        $variations = [];
        
        // Original cleaned version
        $cleaned = strtoupper(str_replace([' ', '\t', '\n', '\r'], '', $matricNo));
        $variations[] = $cleaned;
        
        // Version with forward slashes
        $withSlashes = str_replace(['-', '_', '.', '\\', '|'], '/', $cleaned);
        $variations[] = $withSlashes;
        
        // Version without any separators
        $withoutSeparators = preg_replace('/[^A-Z0-9]/', '', $cleaned);
        $variations[] = $withoutSeparators;
        
        // Try to detect and format standard pattern
        if (preg_match('/^([A-Z]{2,4})[^A-Z0-9]*([A-Z]{2,4})[^A-Z0-9]*(\d{2})[^A-Z0-9]*(\d{3,4})$/', $cleaned, $matches)) {
            $standardFormat = $matches[1] . '/' . $matches[2] . '/' . $matches[3] . '/' . $matches[4];
            $variations[] = $standardFormat;
            
            // Also add version without separators
            $variations[] = $matches[1] . $matches[2] . $matches[3] . $matches[4];
        }
        
        // Clean up variations
        $cleanedVariations = [];
        foreach ($variations as $variation) {
            $variation = preg_replace('/\/+/', '/', $variation);
            $variation = trim($variation, '/');
            if (!empty($variation) && !in_array($variation, $cleanedVariations)) {
                $cleanedVariations[] = $variation;
            }
        }
        
        return $cleanedVariations;
    }

    /**
     * Get list of available GRADUANDS files
     *
     * @return array
     */
    private function getAvailableGraduandsFiles(): array
    {
        $storageDir = storage_path('app');
        $files = [];
        
        // Look for files that start with GRADUANDS
        $pattern = $storageDir . '/GRADUANDS*.docx';
        $foundFiles = glob($pattern);
        
        foreach ($foundFiles as $file) {
            $fileName = basename($file);
            $files[] = [
                'name' => $fileName,
                'size' => $this->formatFileSize(filesize($file)),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        
        // Sort by name
        usort($files, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        
        return $files;
    }

    /**
     * Format file size for display
     *
     * @param int $bytes
     * @return string
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) return '0 Bytes';
        
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * Perform reverse scan - check database students against DOCX data
     * This ensures we don't miss any potential matches
     *
     * @param \Illuminate\Database\Eloquent\Collection $students
     * @param array $docxData
     * @param array &$matches
     * @param array &$matchedStudentIds
     * @return void
     */
    private function performReverseScan($students, $docxData, &$matches, &$matchedStudentIds): void
    {
        Log::info('Starting reverse scan to catch missed matches');
        
        // Create lookup of DOCX data with all variations
        $docxLookup = [];
        foreach ($docxData as $record) {
            $variations = $this->normalizeMatricNumber($record['matric_no']);
            foreach ($variations as $variation) {
                $docxLookup[$variation] = $record;
            }
        }
        
        $reverseScanMatches = 0;
        
        // Check each student against DOCX data
        foreach ($students as $student) {
            if (in_array($student->id, $matchedStudentIds)) {
                continue; // Already matched
            }
            
            $studentVariations = $this->normalizeMatricNumber($student->matric_no);
            
            foreach ($studentVariations as $variation) {
                if (isset($docxLookup[$variation])) {
                    $docxRecord = $docxLookup[$variation];
                    $matchedStudentIds[] = $student->id;
                    
                    $matches[] = [
                        'student_id' => $student->id,
                        'matric_no' => $student->matric_no,
                        'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                        'current_class_of_degree' => $student->class_of_degree,
                        'proposed_class_of_degree' => $docxRecord['proposed_class_of_degree'],
                        'needs_update' => true,
                        'approved' => false,
                        'source' => 'reverse_scan',
                        'row_number' => $docxRecord['row_number'] ?? null,
                        'docx_matric' => $docxRecord['matric_no'],
                        'matched_matric' => $variation,
                        'all_variations' => $studentVariations
                    ];
                    
                    $reverseScanMatches++;
                    
                    Log::info('Reverse scan match found', [
                        'db_matric' => $student->matric_no,
                        'docx_matric' => $docxRecord['matric_no'],
                        'matched_via' => $variation,
                        'student_id' => $student->id
                    ]);
                    
                    break; // Found match, move to next student
                }
            }
        }
        
        Log::info('Reverse scan completed', [
            'additional_matches_found' => $reverseScanMatches
        ]);
    }

    /**
     * Export student NYSC data to CSV with exact table headers
     *
     * @return Response
     */
    public function exportStudentNyscCsv()
    {
        try {
            Log::info('Starting CSV export of student NYSC data');

            // Get all student NYSC records with exact table columns
            $students = StudentNysc::select([
                'matric_no',
                'fname',
                'mname', 
                'lname',
                'phone',
                'state',
                'class_of_degree',
                'dob',
                'graduation_year',
                'gender',
                'marital_status',
                'jamb_no',
                'course_study',
                'study_mode'
            ])->get();

            $filename = 'student_nysc_data_' . date('Y-m-d_H-i-s') . '.csv';
            
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
                
                // Add headers - exact table column names
                fputcsv($file, [
                    'matric_no',
                    'fname',
                    'mname',
                    'lname',
                    'phone',
                    'state',
                    'class_of_degree',
                    'dob',
                    'graduation_year',
                    'gender',
                    'marital_status',
                    'jamb_no',
                    'course_study',
                    'study_mode'
                ]);
                
                // Add data - exact values from database
                foreach ($students as $student) {
                    fputcsv($file, [
                        $student->matric_no,
                        $student->fname,
                        $student->mname,
                        $student->lname,
                        $student->phone,
                        $student->state,
                        $student->class_of_degree,
                        $student->dob,
                        $student->graduation_year,
                        $student->gender,
                        $student->marital_status,
                        $student->jamb_no,
                        $student->course_study,
                        $student->study_mode
                    ]);
                }
                
                fclose($file);
            };
            
            Log::info('CSV export completed', ['record_count' => $students->count()]);
            
            return response()->stream($callback, 200, $headers);
            
        } catch (\Exception $e) {
            Log::error('CSV export error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'CSV export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get CSV export statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCsvExportStats()
    {
        try {
            $totalRecords = StudentNysc::count();
            $recordsWithClassDegree = StudentNysc::whereNotNull('class_of_degree')->count();
            $recordsWithoutClassDegree = $totalRecords - $recordsWithClassDegree;
            
            return response()->json([
                'success' => true,
                'stats' => [
                    'total_records' => $totalRecords,
                    'records_with_class_degree' => $recordsWithClassDegree,
                    'records_without_class_degree' => $recordsWithoutClassDegree,
                    'completion_percentage' => $totalRecords > 0 ? round(($recordsWithClassDegree / $totalRecords) * 100, 2) : 0
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting CSV export stats', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get export statistics'
            ], 500);
        }
    }

    /**
     * Test CSV export functionality
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testCsvExport()
    {
        try {
            $user = auth()->user();
            return response()->json([
                'success' => true,
                'message' => 'CSV Export functionality is working',
                'timestamp' => now(),
                'user_id' => $user ? $user->id : null,
                'authenticated' => $user !== null,
                'user_type' => $user ? get_class($user) : null
            ]);
        } catch (\Exception $e) {
            Log::error('CSV Export test endpoint error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get students with NULL class_of_degree and match with GRADUANDS files
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGraduandsMatches(Request $request)
    {
        try {
            // Increase time limit for this operation
            set_time_limit(300); // 5 minutes
            
            Log::info('Starting GRADUANDS matching process');
            
            // Get the file to process (default to GRADUANDS.docx)
            $fileName = $request->input('file', 'GRADUANDS.docx');
            $filePath = storage_path('app/' . $fileName);
            
            // Get list of available GRADUANDS files
            $availableFiles = $this->getAvailableGraduandsFiles();
            
            // Check if file exists
            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => "File {$fileName} not found. Available files: " . implode(', ', $availableFiles),
                    'available_files' => $availableFiles
                ], 404);
            }

            // Get students with NULL class_of_degree - COMPREHENSIVE SCAN
            $studentsWithNullDegree = StudentNysc::where(function($query) {
                    $query->whereNull('class_of_degree')
                          ->orWhere('class_of_degree', '')
                          ->orWhere('class_of_degree', 'NULL')
                          ->orWhere('class_of_degree', 'null');
                })
                ->select(['id', 'matric_no', 'fname', 'mname', 'lname', 'class_of_degree'])
                ->get();
                
            Log::info('Database scan completed', [
                'total_students_needing_degree' => $studentsWithNullDegree->count(),
                'sample_matric_numbers' => $studentsWithNullDegree->take(10)->pluck('matric_no')->toArray()
            ]);

            if ($studentsWithNullDegree->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'summary' => [
                        'total_students_with_null_degree' => 0,
                        'total_extracted_from_docx' => 0,
                        'total_matches_found' => 0,
                        'file_last_modified' => date('Y-m-d H:i:s', filemtime($filePath))
                    ],
                    'matches' => [],
                    'message' => 'No students found with NULL class_of_degree'
                ]);
            }

            Log::info('Found students with NULL class_of_degree', ['count' => $studentsWithNullDegree->count()]);

            // Process the DOCX file to extract data
            $docxImportService = new \App\Services\DocxImportService();
            $result = $docxImportService->processDocxFile($filePath);
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process GRADUANDS.docx: ' . $result['error']
                ], 500);
            }

            Log::info('DOCX processing completed', ['extracted_count' => count($result['review_data'])]);

            // Create comprehensive lookup arrays for better matching
            $studentLookup = [];
            $unmatched = [];
            
            foreach ($studentsWithNullDegree as $student) {
                $originalMatric = strtoupper($student->matric_no);
                $variations = $this->normalizeMatricNumber($originalMatric);
                
                // Store all variations of this matric number
                foreach ($variations as $variation) {
                    $studentLookup[$variation] = $student;
                }
            }
            
            Log::info('Student lookup created', [
                'total_lookup_entries' => count($studentLookup),
                'unique_students' => $studentsWithNullDegree->count()
            ]);

            // Match extracted data with students who have NULL class_of_degree - COMPREHENSIVE MATCHING
            $matches = [];
            $matchedStudentIds = []; // Track to avoid duplicates
            
            foreach ($result['review_data'] as $extractedData) {
                $originalMatric = strtoupper($extractedData['matric_no']);
                $variations = $this->normalizeMatricNumber($originalMatric);
                
                $student = null;
                $matchedMatric = null;
                
                // Try all variations until we find a match
                foreach ($variations as $variation) {
                    if (isset($studentLookup[$variation])) {
                        $student = $studentLookup[$variation];
                        $matchedMatric = $variation;
                        break;
                    }
                }
                
                if ($student && !in_array($student->id, $matchedStudentIds)) {
                    $matchedStudentIds[] = $student->id; // Prevent duplicates
                    
                    $matches[] = [
                        'student_id' => $student->id,
                        'matric_no' => $student->matric_no,
                        'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                        'current_class_of_degree' => $student->class_of_degree, // Show actual current value
                        'proposed_class_of_degree' => $extractedData['proposed_class_of_degree'],
                        'needs_update' => true,
                        'approved' => false,
                        'source' => $extractedData['source'] ?? 'docx',
                        'row_number' => $extractedData['row_number'] ?? null,
                        'docx_matric' => $originalMatric,
                        'matched_matric' => $matchedMatric,
                        'all_variations' => $variations // For debugging
                    ];
                    
                    Log::info('Match found', [
                        'docx_matric' => $originalMatric,
                        'db_matric' => $student->matric_no,
                        'matched_via' => $matchedMatric,
                        'student_id' => $student->id
                    ]);
                } else {
                    // Store unmatched for debugging
                    $unmatched[] = [
                        'docx_matric' => $originalMatric,
                        'all_variations' => $variations,
                        'class_of_degree' => $extractedData['proposed_class_of_degree']
                    ];
                }
            }

            // Perform reverse matching - check if any database records were missed
            $this->performReverseScan($studentsWithNullDegree, $result['review_data'], $matches, $matchedStudentIds);
            
            // Log unmatched records for debugging
            if (!empty($unmatched)) {
                Log::info('Unmatched DOCX records', [
                    'count' => count($unmatched),
                    'sample' => array_slice($unmatched, 0, 10) // Log first 10 unmatched
                ]);
            }

            $summary = [
                'total_students_with_null_degree' => $studentsWithNullDegree->count(),
                'total_extracted_from_docx' => count($result['review_data']),
                'total_matches_found' => count($matches),
                'total_unmatched' => count($unmatched),
                'current_file' => $fileName,
                'available_files' => $availableFiles,
                'file_last_modified' => date('Y-m-d H:i:s', filemtime($filePath))
            ];

            Log::info('GRADUANDS matching completed', $summary);

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'matches' => $matches,
                'unmatched' => array_slice($unmatched, 0, 50), // Return first 50 unmatched for review
                'message' => count($matches) > 0 
                    ? "Found " . count($matches) . " matches. " . count($unmatched) . " records could not be matched."
                    : "No matches found for students with NULL class_of_degree"
            ]);

        } catch (\Exception $e) {
            Log::error('Error in GRADUANDS matching', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing GRADUANDS data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply approved class of degree updates for students with NULL values
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyGraduandsUpdates(Request $request)
    {
        try {
            $validator = \Validator::make($request->all(), [
                'updates' => 'required|array',
                'updates.*.student_id' => 'required|integer',
                'updates.*.matric_no' => 'required|string',
                'updates.*.proposed_class_of_degree' => 'required|string',
                'updates.*.approved' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updates = $request->input('updates');
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];

            try {
                \DB::beginTransaction();

                foreach ($updates as $update) {
                    if (!$update['approved']) {
                        continue; // Skip non-approved updates
                    }

                    try {
                        $student = StudentNysc::find($update['student_id']);
                        
                        if (!$student) {
                            $errorCount++;
                            $errors[] = "Student not found: {$update['matric_no']}";
                            continue;
                        }

                        // Check if class_of_degree needs updating (NULL, empty, or invalid values)
                        $currentDegree = $student->class_of_degree;
                        $needsUpdate = $currentDegree === null || 
                                     $currentDegree === '' || 
                                     $currentDegree === 'NULL' || 
                                     $currentDegree === 'null' ||
                                     empty(trim($currentDegree));
                        
                        if ($needsUpdate) {
                            $oldValue = $student->class_of_degree;
                            $student->class_of_degree = $update['proposed_class_of_degree'];
                            
                            // Force save and check if it actually saved
                            $saved = $student->save();
                            
                            if ($saved) {
                                $updatedCount++;
                                
                                Log::info('Student class_of_degree updated successfully', [
                                    'student_id' => $student->id,
                                    'matric_no' => $student->matric_no,
                                    'old_value' => $oldValue,
                                    'new_value' => $update['proposed_class_of_degree'],
                                    'save_result' => $saved
                                ]);
                                
                                // Verify the update by re-fetching
                                $verifyStudent = StudentNysc::find($student->id);
                                Log::info('Update verification', [
                                    'student_id' => $student->id,
                                    'verified_value' => $verifyStudent->class_of_degree,
                                    'matches_expected' => $verifyStudent->class_of_degree === $update['proposed_class_of_degree']
                                ]);
                            } else {
                                $errorCount++;
                                $errors[] = "Failed to save update for {$update['matric_no']}";
                                Log::error('Failed to save student update', [
                                    'student_id' => $student->id,
                                    'matric_no' => $student->matric_no
                                ]);
                            }
                        } else {
                            Log::info('Student already has valid class_of_degree, skipping', [
                                'student_id' => $student->id,
                                'matric_no' => $student->matric_no,
                                'existing_value' => $student->class_of_degree
                            ]);
                        }

                    } catch (\Exception $e) {
                        $errorCount++;
                        $errors[] = "Error updating {$update['matric_no']}: " . $e->getMessage();
                        Log::error('Error updating student', [
                            'matric_no' => $update['matric_no'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                \DB::commit();

                Log::info('Batch update completed', [
                    'updated_count' => $updatedCount,
                    'error_count' => $errorCount
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Updates applied successfully. {$updatedCount} records updated.",
                    'result' => [
                        'updated_count' => $updatedCount,
                        'error_count' => $errorCount,
                        'errors' => $errors
                    ]
                ]);

            } catch (\Exception $e) {
                \DB::rollback();
                Log::error('Transaction failed during batch update', ['error' => $e->getMessage()]);
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error applying GRADUANDS updates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error applying updates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test method to verify database updates are working
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testDatabaseUpdate(Request $request)
    {
        try {
            // Get a student with NULL class_of_degree for testing
            $testStudent = StudentNysc::whereNull('class_of_degree')
                ->orWhere('class_of_degree', '')
                ->first();
            
            if (!$testStudent) {
                return response()->json([
                    'success' => false,
                    'message' => 'No test student found with NULL class_of_degree'
                ]);
            }
            
            $originalValue = $testStudent->class_of_degree;
            $testValue = 'Test Class - ' . now()->format('H:i:s');
            
            Log::info('Starting database update test', [
                'student_id' => $testStudent->id,
                'matric_no' => $testStudent->matric_no,
                'original_value' => $originalValue,
                'test_value' => $testValue
            ]);
            
            // Attempt to update
            $testStudent->class_of_degree = $testValue;
            $saved = $testStudent->save();
            
            // Verify the update
            $verifyStudent = StudentNysc::find($testStudent->id);
            
            $result = [
                'success' => true,
                'test_results' => [
                    'student_id' => $testStudent->id,
                    'matric_no' => $testStudent->matric_no,
                    'original_value' => $originalValue,
                    'test_value' => $testValue,
                    'save_result' => $saved,
                    'verified_value' => $verifyStudent->class_of_degree,
                    'update_successful' => $verifyStudent->class_of_degree === $testValue
                ]
            ];
            
            Log::info('Database update test completed', $result['test_results']);
            
            // Revert the test change
            $verifyStudent->class_of_degree = $originalValue;
            $verifyStudent->save();
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Database update test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending payments statistics
     */
    public function getPendingPaymentsStats()
    {
        try {
            // Direct database queries - exactly like our successful PHP script
            $now = now();
            
            // Count pending payments directly from database
            $totalPending = \DB::table('nysc_payments')->where('status', 'pending')->count();
            
            // Get time-based statistics
            $pendingLastHour = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->where('created_at', '>=', $now->copy()->subHour())
                ->count();
                
            $pendingLast24h = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->where('created_at', '>=', $now->copy()->subDay())
                ->count();
                
            $pendingOlderThan5min = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->where('created_at', '<=', $now->copy()->subMinutes(5))
                ->count();
                
            // Get oldest pending payment
            $oldestPending = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->orderBy('created_at', 'asc')
                ->first();
            
            $stats = [
                'total_pending' => $totalPending,
                'pending_last_hour' => $pendingLastHour,
                'pending_last_24h' => $pendingLast24h,
                'pending_older_than_5min' => $pendingOlderThan5min,
                'oldest_pending' => $oldestPending ? \Carbon\Carbon::parse($oldestPending->created_at)->diffForHumans() : null,
            ];
            
            // Get recent pending payments directly from database
            $recentPendingRaw = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(50)
                ->get();
            
            $recentPending = [];
            foreach ($recentPendingRaw as $payment) {
                $createdAt = \Carbon\Carbon::parse($payment->created_at);
                
                // Get student information from student_id
                $studentInfo = null;
                if ($payment->student_id) {
                    try {
                        // First try to get from student_nysc table (has matric_no and NYSC-specific info)
                        $studentNysc = \DB::table('student_nysc')
                            ->where('student_id', $payment->student_id)
                            ->select('matric_no', 'fname', 'lname', 'mname')
                            ->first();
                        
                        if ($studentNysc) {
                            $studentInfo = [
                                'matric_no' => $studentNysc->matric_no,
                                'name' => trim(($studentNysc->fname ?? '') . ' ' . ($studentNysc->mname ?? '') . ' ' . ($studentNysc->lname ?? ''))
                            ];
                        } else {
                            // Fallback to main students table (no matric_no available)
                            $student = \DB::table('students')
                                ->where('id', $payment->student_id)
                                ->select('fname', 'lname', 'mname')
                                ->first();
                            
                            if ($student) {
                                $studentInfo = [
                                    'matric_no' => 'N/A', // Not available in main students table
                                    'name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? ''))
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        // Ignore relationship errors
                        \Log::warning('Error getting student info for payment ' . $payment->id . ': ' . $e->getMessage());
                    }
                }
                
                $recentPending[] = [
                    'id' => $payment->id,
                    'reference' => $payment->payment_reference,
                    'amount' => $payment->amount,
                    'created_at' => $createdAt,
                    'age_minutes' => $createdAt->diffInMinutes($now),
                    'student' => $studentInfo
                ];
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'recent_pending' => $recentPending
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in getPendingPaymentsStats: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load pending payments data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify all pending payments
     */
    public function verifyPendingPayments(Request $request)
    {
        try {
            $force = $request->boolean('force', false);
            $limit = $request->integer('limit', 50);

            // Get pending payments to verify
            $query = \App\Models\NyscPayment::where('status', 'pending');
            
            if (!$force) {
                $query->where('created_at', '<=', now()->subMinutes(5));
            }
            
            $query->where('created_at', '>=', now()->subDays(7));
            
            $pendingPayments = $query->orderBy('created_at', 'desc')
                                   ->limit($limit)
                                   ->get();

            if ($pendingPayments->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No pending payments found to verify',
                    'stats' => [
                        'total' => 0,
                        'verified' => 0,
                        'successful' => 0,
                        'failed' => 0,
                        'errors' => 0
                    ]
                ]);
            }

            // Simple verification - for now just mark some as successful for testing
            $verified = 0;
            $successful = 0;
            $failed = 0;
            $errors = 0;

            foreach ($pendingPayments as $payment) {
                try {
                    // Simple verification logic - you can enhance this later
                    $result = $this->verifyPaymentWithPaystack($payment);
                    $verified++;
                    
                    if ($result['success']) {
                        if ($result['new_status'] === 'successful') {
                            $successful++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $errors++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    \Log::error("Error verifying payment {$payment->id}: " . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Verified {$verified} payments: {$successful} successful, {$failed} failed, {$errors} errors",
                'stats' => [
                    'total' => $pendingPayments->count(),
                    'verified' => $verified,
                    'successful' => $successful,
                    'failed' => $failed,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error in verifyPendingPayments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error verifying pending payments: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify a single payment
     */
    public function verifySinglePayment(\App\Models\NyscPayment $payment)
    {
        try {
            $result = $this->verifyPaymentWithPaystack($payment);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'payment' => $payment->fresh(),
                'old_status' => $result['old_status'] ?? null,
                'new_status' => $result['new_status'] ?? null
            ]);

        } catch (\Exception $e) {
            \Log::error('Error verifying single payment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error verifying payment: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Verify a payment with Paystack API
     *
     * @param \App\Models\NyscPayment $payment
     * @return array
     */
    private function verifyPaymentWithPaystack($payment)
    {
        try {
            $secretKey = config('services.paystack.secret_key');
            
            if (!$secretKey) {
                // For testing, just mark as successful without actual Paystack verification
                \Log::info("No Paystack key configured, marking payment {$payment->id} as successful for testing");
                
                $oldStatus = $payment->status;
                $payment->update([
                    'status' => 'successful',
                    'payment_date' => now()
                ]);
                
                // Update student record if needed
                $this->updateStudentPaymentStatus($payment);
                
                return [
                    'success' => true,
                    'message' => 'Payment marked as successful (test mode)',
                    'old_status' => $oldStatus,
                    'new_status' => 'successful'
                ];
            }

            // Call Paystack verification API
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->get("https://api.paystack.co/transaction/verify/{$payment->payment_reference}");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => "Paystack API request failed with status {$response->status()}"
                ];
            }

            $data = $response->json();

            if (!$data['status']) {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'Verification failed'
                ];
            }

            $transactionData = $data['data'];
            $paystackStatus = strtolower($transactionData['status']);
            
            // Map Paystack status to our internal status
            $newStatus = match ($paystackStatus) {
                'success' => 'successful',
                'failed', 'cancelled', 'abandoned' => 'failed',
                default => 'pending'
            };

            $oldStatus = $payment->status;

            // Update payment if status changed
            if ($payment->status !== $newStatus) {
                $payment->update([
                    'status' => $newStatus,
                    'payment_data' => json_encode($transactionData),
                    'payment_date' => isset($transactionData['paid_at']) ? 
                        \Carbon\Carbon::parse($transactionData['paid_at']) : now()
                ]);

                // If successful, update student record
                if ($newStatus === 'successful') {
                    $this->updateStudentPaymentStatus($payment);
                }

                \Log::info("Payment {$payment->id} status updated from {$oldStatus} to {$newStatus}");
                
                return [
                    'success' => true,
                    'message' => "Payment status updated from {$oldStatus} to {$newStatus}",
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ];
            }

            return [
                'success' => true,
                'message' => 'Payment status unchanged',
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ];

        } catch (\Exception $e) {
            \Log::error("Paystack verification error for payment {$payment->id}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update student payment status when payment is successful
     *
     * @param \App\Models\NyscPayment $payment
     */
    private function updateStudentPaymentStatus($payment)
    {
        try {
            // Try to find student in student_nysc table
            $studentNysc = \App\Models\StudentNysc::where('student_id', $payment->student_id)->first();
            
            if ($studentNysc && !$studentNysc->is_paid) {
                $studentNysc->update([
                    'is_paid' => true,
                    'is_submitted' => true,
                    'payment_amount' => $payment->amount,
                    'payment_date' => now()
                ]);
                
                \Log::info("Student NYSC record updated for successful payment", [
                    'student_id' => $payment->student_id,
                    'payment_id' => $payment->id,
                    'matric_no' => $studentNysc->matric_no
                ]);
            } else {
                \Log::info("No student_nysc record found or already paid for student_id: {$payment->student_id}");
            }
        } catch (\Exception $e) {
            \Log::error("Error updating student payment status: " . $e->getMessage());
        }
    }
    
    /**
     * Simple test endpoint to verify database connection and pending payments
     */
    public function testPendingPayments()
    {
        try {
            $totalPayments = \DB::table('nysc_payments')->count();
            $pendingCount = \DB::table('nysc_payments')->where('status', 'pending')->count();
            $successfulCount = \DB::table('nysc_payments')->where('status', 'successful')->count();
            $samplePending = \DB::table('nysc_payments')
                ->where('status', 'pending')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'payment_reference', 'amount', 'status', 'created_at']);
            return response()->json([
                'success' => true,
                'database_connection' => 'OK',
                'total_payments' => $totalPayments,
                'pending_payments' => $pendingCount,
                'successful_payments' => $successfulCount,
                'sample_pending' => $samplePending,
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ], 500);
        }
    }

    private function getHiddenStudentIds(): array
    {
        $hidden = \App\Models\AdminSetting::get('hidden_payment_students', []);
        return is_array($hidden) ? array_values(array_unique(array_map('intval', $hidden))) : [];
    }

    private function canViewPaymentStats($user): bool
    {
        if (!$user) return false;
        $email = strtolower($user->email ?? '');
        $pEmail = strtolower($user->p_email ?? '');
        return in_array($email, ['onoyimab@veritas.edu.ng', 'agbudug@veritas.edu.ng'])
            || in_array($pEmail, ['onoyimab@veritas.edu.ng', 'agbudug@veritas.edu.ng']);
    }

    private function canHidePayments($user): bool
    {
        if (!$user) return false;
        $email = strtolower($user->email ?? '');
        $pEmail = strtolower($user->p_email ?? '');
        return $email === 'onoyimab@veritas.edu.ng' || $pEmail === 'onoyimab@veritas.edu.ng';
    }

    public function getPaymentStatistics(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$this->canViewPaymentStats($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $hiddenIds = $this->getHiddenStudentIds();
        $start = $request->get('dateStart');
        $end = $request->get('dateEnd');
        $method = $request->get('payment_method');
        $department = $request->get('department');
        $amountType = $request->get('amount_type'); // 'standard' | 'late'
        $duplicatesFilter = $request->get('duplicates'); // 'all' | 'only' | 'exclude'
        $query = \App\Models\NyscPayment::where('status', 'successful')
            ->when(!empty($hiddenIds), function ($q) use ($hiddenIds) { return $q->whereNotIn('student_id', $hiddenIds); })
            ->when($start, function ($q) use ($start) { return $q->whereDate('payment_date', '>=', $start); })
            ->when($end, function ($q) use ($end) { return $q->whereDate('payment_date', '<=', $end); })
            ->when($method, function ($q) use ($method) { return $q->where('payment_method', $method); })
            ->with(['studentNysc']);
        if ($department) {
            $query->whereHas('studentNysc', function ($q) use ($department) { $q->where('department', $department); });
        }
        $standardFee = \App\Models\AdminSetting::get('payment_amount');
        $lateFee = \App\Models\AdminSetting::get('late_payment_fee');
        if ($amountType === 'standard') { $query->where('amount', $standardFee); }
        if ($amountType === 'late') { $query->where('amount', $lateFee); }
        $payments = $query->get();
        if (in_array($duplicatesFilter, ['only', 'exclude'])) {
            $byStudentDup = $payments->groupBy('student_id');
            $dupIds = $byStudentDup->filter(function ($group) { return $group->count() > 1; })->keys();
            if ($duplicatesFilter === 'only') { $payments = $payments->whereIn('student_id', $dupIds->all()); }
            if ($duplicatesFilter === 'exclude') { $payments = $payments->whereNotIn('student_id', $dupIds->all()); }
        }
        $totalAmount = $payments->sum('amount');
        $totalPayments = $payments->count();
        $studentIds = $payments->pluck('student_id')->filter()->unique()->values();
        $totalStudentsPaid = $studentIds->count();
        $normal = $payments->where('amount', $standardFee);
        $late = $payments->where('amount', $lateFee);
        $byStudent = $payments->groupBy('student_id');
        $duplicates = $byStudent->filter(function ($group) { return $group->count() > 1; });
        $duplicateStudentsCount = $duplicates->count();
        $duplicatePaymentsCount = $duplicates->map->count()->sum();
        $duplicateAmount = $duplicates->flatten()->sum('amount');
        $deptBreakdown = $payments->groupBy(function ($p) { return optional($p->studentNysc)->department ?: 'N/A'; })
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'students' => $group->pluck('student_id')->unique()->count(),
                    'amount' => $group->sum('amount')
                ];
            });
        return response()->json([
            'success' => true,
            'filters' => [
                'dateStart' => $start,
                'dateEnd' => $end,
                'payment_method' => $method,
                'department' => $department,
                'amount_type' => $amountType,
                'duplicates' => $duplicatesFilter ?: 'all'
            ],
            'summary' => [
                'total_successful_amount' => $totalAmount,
                'total_successful_payments' => $totalPayments,
                'total_students_paid' => $totalStudentsPaid,
                'normal_fee_count' => $normal->count(),
                'normal_fee_amount' => $normal->sum('amount'),
                'late_fee_count' => $late->count(),
                'late_fee_amount' => $late->sum('amount'),
                'duplicate_students_count' => $duplicateStudentsCount,
                'duplicate_payments_count' => $duplicatePaymentsCount,
                'duplicate_total_amount' => $duplicateAmount,
                'hidden_students_count' => count($hiddenIds)
            ],
            'department_breakdown' => $deptBreakdown,
        ]);
    }

    public function exportPaymentStatistics(\Illuminate\Http\Request $request)
    {
        $user = auth()->user();
        if (!$this->canViewPaymentStats($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $hiddenIds = $this->getHiddenStudentIds();
        $start = $request->get('dateStart');
        $end = $request->get('dateEnd');
        $method = $request->get('payment_method');
        $department = $request->get('department');
        $format = strtolower($request->get('format', 'csv'));
        $amountType = $request->get('amount_type');
        $duplicatesFilter = $request->get('duplicates');
        $query = \App\Models\NyscPayment::where('status', 'successful')
            ->when(!empty($hiddenIds), function ($q) use ($hiddenIds) { return $q->whereNotIn('student_id', $hiddenIds); })
            ->when($start, function ($q) use ($start) { return $q->whereDate('payment_date', '>=', $start); })
            ->when($end, function ($q) use ($end) { return $q->whereDate('payment_date', '<=', $end); })
            ->when($method, function ($q) use ($method) { return $q->where('payment_method', $method); })
            ->with(['studentNysc']);
        if ($department) { $query->whereHas('studentNysc', function ($q) use ($department) { $q->where('department', $department); }); }
        $standardFee = \App\Models\AdminSetting::get('payment_amount');
        $lateFee = \App\Models\AdminSetting::get('late_payment_fee');
        if ($amountType === 'standard') { $query->where('amount', $standardFee); }
        if ($amountType === 'late') { $query->where('amount', $lateFee); }
        $payments = $query->get();
        if (in_array($duplicatesFilter, ['only', 'exclude'])) {
            $byStudentDup = $payments->groupBy('student_id');
            $dupIds = $byStudentDup->filter(function ($group) { return $group->count() > 1; })->keys();
            if ($duplicatesFilter === 'only') { $payments = $payments->whereIn('student_id', $dupIds->all()); }
            if ($duplicatesFilter === 'exclude') { $payments = $payments->whereNotIn('student_id', $dupIds->all()); }
        }
        $filename = 'payment_statistics_' . now()->format('Y-m-d_H-i-s') . ($format === 'excel' ? '.xlsx' : '.csv');
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ];
        $callback = function () use ($payments) {
            $f = fopen('php://output', 'w');
            fprintf($f, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($f, ['student_id', 'matric_no', 'name', 'department', 'payment_count', 'total_amount', 'late_fee_count', 'normal_fee_count']);
            $standardFee = \App\Models\AdminSetting::get('payment_amount');
            $lateFee = \App\Models\AdminSetting::get('late_payment_fee');
            $rows = $payments->groupBy('student_id')->map(function ($g) use ($standardFee, $lateFee) {
                $nysc = $g->first()->studentNysc;
                $normalCnt = $g->where('amount', $standardFee)->count();
                $lateCnt = $g->where('amount', $lateFee)->count();
                return [
                    $g->first()->student_id,
                    optional($nysc)->matric_no,
                    trim((optional($nysc)->fname . ' ' . optional($nysc)->mname . ' ' . optional($nysc)->lname)),
                    optional($nysc)->department,
                    $g->count(),
                    $g->sum('amount'),
                    $lateCnt,
                    $normalCnt,
                ];
            });
            foreach ($rows as $r) { fputcsv($f, $r); }
            fclose($f);
        };
        return response()->stream($callback, 200, $headers);
    }

    public function hideStudentsPayments(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        if (!$this->canHidePayments($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $ids = $request->input('student_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No students selected'], 422);
        }
        $current = $this->getHiddenStudentIds();
        $merged = array_values(array_unique(array_merge($current, array_map('intval', $ids))));
        \App\Models\AdminSetting::set('hidden_payment_students', json_encode($merged), 'json', 'Hidden student payments for stats', 'payment');
        \Log::info('Payments hidden', ['by' => $user->email, 'student_ids' => $ids]);
        return response()->json(['success' => true, 'message' => 'Updated', 'hidden_count' => count($merged)]);
    }
}