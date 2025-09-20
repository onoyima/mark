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
    public function getDuplicatePayments(): \Illuminate\Http\JsonResponse
    {
        try {
            // Find students with more than one successful payment
            $studentsWithMultiplePayments = Student::withCount([
                'payments as successful_payments_count' => function ($query) {
                    $query->where('status', 'successful');
                }
            ])
            ->having('successful_payments_count', '>', 1)
            ->with(['payments' => function ($query) {
                $query->where('status', 'successful')
                      ->orderBy('payment_date', 'desc');
            }, 'nyscRecord'])
            ->get()
            ->map(function ($student) {
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
                            'transaction_id' => $payment->transaction_id,
                        ];
                    }),
                    'total_paid' => $student->payments->sum('amount'),
                ];
            });

            return response()->json([
                'duplicate_payments' => $studentsWithMultiplePayments,
                'total' => $studentsWithMultiplePayments->count(),
                'statistics' => [
                    'total_duplicate_amount' => $studentsWithMultiplePayments->sum('total_paid'),
                    'average_overpayment' => $studentsWithMultiplePayments->count() > 0 ? 
                        $studentsWithMultiplePayments->sum(function ($student) {
                            // Calculate overpayment (total - standard fee)
                            $standardFee = AdminSetting::get('payment_amount', 2000);
                            return $student['total_paid'] - $standardFee;
                        }) / $studentsWithMultiplePayments->count() : 0,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching duplicate payments data: ' . $e->getMessage());
            
            return response()->json([
                'duplicate_payments' => [],
                'total' => 0,
                'statistics' => [
                    'total_duplicate_amount' => 0,
                    'average_overpayment' => 0,
                ],
                'error' => 'Failed to load duplicate payments data'
            ], 500);
        }
    }
}