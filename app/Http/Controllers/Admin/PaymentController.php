<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NyscPayment;
use App\Services\PaystackService;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Display a listing of pending payments
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $pendingPayments = NyscPayment::where('status', 'pending')
            ->with('student')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.payments.index', compact('pendingPayments'));
    }

    /**
     * Verify a specific payment with Paystack
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function verify($id)
    {
        $payment = NyscPayment::findOrFail($id);

        $result = $this->paystackService->updatePaymentStatus($payment);

        if ($result['success']) {
            return redirect()->route('admin.payments.index')
                ->with('success', $result['message']);
        } else {
            return redirect()->route('admin.payments.index')
                ->with('error', $result['message']);
        }
    }

    /**
     * Verify all pending payments
     *
     * @return \Illuminate\Http\Response
     */
    public function verifyAll()
    {
        $pendingPayments = NyscPayment::where('status', 'pending')->get();

        $successCount = 0;
        $failCount = 0;

        foreach ($pendingPayments as $payment) {
            $result = $this->paystackService->updatePaymentStatus($payment);

            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                Log::warning('Failed to verify payment', [
                    'payment_id' => $payment->id,
                    'reference' => $payment->payment_reference,
                    'message' => $result['message']
                ]);
            }
        }

        return redirect()->route('admin.payments.index')
            ->with('success', "Verification completed: $successCount payments verified successfully, $failCount failed.");
    }

    /**
     * Show payment details
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $payment = NyscPayment::with(['student', 'studentNysc'])->findOrFail($id);

        return view('admin.payments.show', compact('payment'));
    }
}
