<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\AdminSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NyscDuplicatePaymentController extends Controller
{
    /**
     * Get students who have made duplicate payments
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDuplicatePayments(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $search = $request->get('search', '');
            
            // Find students with more than one successful payment
            $query = Student::withCount([
                'payments as successful_payments_count' => function ($query) {
                    $query->where('status', 'successful');
                }
            ])
            ->having('successful_payments_count', '>', 1)
            ->with(['payments' => function ($query) {
                $query->where('status', 'successful')
                      ->orderBy('payment_date', 'desc');
            }, 'nyscRecord']);
            
            // Apply search if provided
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('fname', 'like', "%{$search}%")
                      ->orWhere('lname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhereHas('nyscRecord', function($q2) use ($search) {
                          $q2->where('matric_no', 'like', "%{$search}%")
                            ->orWhere('department', 'like', "%{$search}%");
                      });
                });
            }
            
            $studentsWithMultiplePayments = $query->get()
            ->map(function ($student) {
                $standardFee = AdminSetting::get('payment_amount', 2000);
                $totalPaid = $student->payments->sum('amount');
                
                return [
                    'student_id' => $student->id,
                    'student_name' => $student->fname . ' ' . $student->lname,
                    'email' => $student->email,
                    'matric_number' => $student->nyscRecord ? $student->nyscRecord->matric_no : 'N/A',
                    'department' => $student->nyscRecord ? $student->nyscRecord->department : 'N/A',
                    'payments_count' => $student->successful_payments_count,
                    'payments' => $student->payments->map(function ($payment) {
                        return [
                            'id' => $payment->id,
                            'amount' => $payment->amount,
                            'payment_reference' => $payment->payment_reference,
                            'payment_date' => $payment->payment_date,
                            'payment_method' => $payment->payment_method ?? 'paystack',
                            'payment_status' => 'successful',
                            'transaction_reference' => $payment->payment_reference,
                        ];
                    }),
                    'total_paid' => $totalPaid,
                    'expected_amount' => $standardFee,
                    'overpayment' => $totalPaid - $standardFee,
                ];
            });

            // Handle pagination
            $page = request()->get('page', 1);
            $limit = request()->get('limit', 10);
            $total = $studentsWithMultiplePayments->count();
            $totalPages = ceil($total / $limit);
            
            // Apply pagination manually
            $offset = ($page - 1) * $limit;
            $paginatedPayments = $studentsWithMultiplePayments->slice($offset, $limit)->values();
            
            return response()->json([
                'duplicate_payments' => $paginatedPayments,
                'total' => $total,
                'totalPages' => $totalPages,
                'statistics' => [
                    'total_students' => $total,
                    'total_duplicate_amount' => $studentsWithMultiplePayments->sum('total_paid'),
                    'average_overpayment' => $studentsWithMultiplePayments->count() > 0 ? 
                        $studentsWithMultiplePayments->sum(function ($student) {
                            return $student['overpayment'];
                        }) / $studentsWithMultiplePayments->count() : 0,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching duplicate payments data: ' . $e->getMessage());
            
            return response()->json([
                'duplicate_payments' => [],
                'total' => 0,
                'totalPages' => 0,
                'statistics' => [
                    'total_students' => 0,
                    'total_duplicate_amount' => 0,
                    'average_overpayment' => 0,
                ],
                'error' => 'Failed to load duplicate payments data'
            ], 500);
        }
    }
}