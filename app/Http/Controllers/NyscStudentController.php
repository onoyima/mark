<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\StudentContact;
use App\Models\StudentMedical;
use App\Models\Department;
use App\Models\State;
use App\Models\StudyMode;
use App\Models\StudentNysc;
use App\Models\NyscPayment;
use App\Models\NyscTempSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NyscStudentController extends Controller
{
    /**
     * Get authenticated student's details
     *
     * @return \Illuminate\Http\JsonResponse
     */
   public function getDetails(): \Illuminate\Http\JsonResponse
{
    $student = Auth::user()->load(['state']);
    $academic = StudentAcademic::where('student_id', $student->id)
        ->with(['studyMode', 'department', 'courseStudy']) // Eager load study mode, department, and course study
        ->first();

    $contact = StudentContact::where('student_id', $student->id)->first();
    $medical = StudentMedical::where('student_id', $student->id)->first();

    // Check if student has already submitted their details (from student_nysc table)
    $nysc = Studentnysc::where('student_id', $student->id)->first();
    $isSubmitted = $nysc && $nysc->is_submitted;

    // Helper function to handle null values
    $handleNull = function($value) {
        return $value ?? 'Not provided';
    };

    // Get latest successful payment if exists
    $latestPayment = null;
    if ($nysc) {
        $latestPayment = $nysc->latestSuccessfulPayment;
    }

    return response()->json([
        'student' => [
            'id' => $student->id,
            'title' => $handleNull($student->title ?? null),
            'fname' => $handleNull($student->fname),
            'mname' => $handleNull($student->mname),
            'lname' => $handleNull($student->lname),
            'gender' => $handleNull($student->gender),
            'dob' => $handleNull($student->dob),
            'state' => $handleNull($student->state->name ?? null),
            'lga' => $handleNull($student->lga_name),
            'city' => $handleNull($student->city),
            'religion' => $handleNull($student->religion),
            'marital_status' => $handleNull($student->marital_status),
            'address' => $handleNull($student->address),
            'phone' => $handleNull($student->phone),
            'username' => $handleNull($student->username),
            'passport' => $student->passport,

        ],
        'academic' => [
            'matric_no' => $handleNull($academic->matric_no ?? null),
            'department' => $handleNull($academic->department->name ?? null),
            'course_study' => $handleNull($academic->courseStudy->name ?? null),
            'level' => $handleNull($academic->level ?? null),
            'jamb_no' => $handleNull($academic->jamb_no ?? null),
            'study_mode_id' => $handleNull($academic->study_mode_id ?? null),
            'study_mode' => $handleNull($academic->studyMode->mode ?? null),
            'graduation_year' => $handleNull(null),
            'cgpa' => $handleNull(null),
        ],
        'nysc' => $nysc ? [
            'fname' => $nysc->fname,
            'lname' => $nysc->lname,
            'mname' => $nysc->mname,
            'gender' => $nysc->gender,
            'dob' => $nysc->dob,
            'marital_status' => $nysc->marital_status,
            'phone' => $nysc->phone,
            'username' => $nysc->email,
            'state' => $nysc->state,
            'course_of_study' => $nysc->course_of_study,
            'jamb_no' => $nysc->jamb_no,
            'study_mode' => $nysc->study_mode,
        ] : null,
        'is_submitted' => $isSubmitted,
        'is_paid' => $nysc ? $nysc->hasSuccessfulPayment() : false,
        'payment_amount' => $latestPayment ? $latestPayment->amount : null,
        'payment_reference' => $latestPayment ? $latestPayment->payment_reference : null,
    ]);
}

    /**
     * Get student analytics data for dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAnalytics(): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();
        
        // Get NYSC record for this student
        $nysc = StudentNysc::where('student_id', $student->id)->first();
        
        // Count submissions to student_nysc table (how many times form was submitted)
        $submissionCount = $nysc ? ($nysc->is_submitted ? 1 : 0) : 0;
        
        // Sum all successful payments for this student
        $totalPayments = 0;
        if ($nysc) {
            $totalPayments = NyscPayment::where('student_id', $student->id)
                ->where('status', 'successful')
                ->sum('amount');
        }
        
        // Count how many times student has updated their NYSC data
        $dataUpdates = $nysc ? $nysc->updated_at->diffInDays($nysc->created_at) + 1 : 0;
        
        return response()->json([
            'submissionCount' => $submissionCount,
            'totalPayments' => $totalPayments,
            'dataUpdates' => $dataUpdates
        ]);
}


    /**
     * Update student's NYSC details (called after successful payment)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDetails(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();

        // Validate the request - handle only fields that exist in student_nysc table
        $validated = $request->validate([
            // Student personal fields
            'fname' => 'required|string|max:100',
            'lname' => 'required|string|max:100',
            'mname' => 'nullable|string|max:100',
            'gender' => 'required|in:Male,Female',
            'dob' => 'required|date',
            'marital_status' => 'required|in:Single,Married,Divorced,Widowed',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'address' => 'required|string',
            'state' => 'required|string|max:100',
            'lga' => 'required|string|max:100',
            'username' => 'nullable|string|max:255',
            'matric_no' => 'required|string|max:50',
            'department' => 'required|string|max:255',
            'level' => 'nullable|string|max:10',
            'graduation_year' => 'nullable|integer|min:2000|max:2030',
            'cgpa' => 'nullable|numeric|min:0|max:5',
            'jamb_no' => 'required|string|max:20',
            'study_mode' => 'required|string|max:100',
        ]);

        // Get NYSC record
        $nysc = StudentNysc::where('student_id', $student->id)->first();

        if (!$nysc) {
            return response()->json([
                'message' => 'No payment record found. Please initiate payment first.',
            ], 404);
        }

        // Check if payment has been made using the new payment system
        if (!$nysc->hasSuccessfulPayment()) {
            return response()->json([
                'message' => 'Payment is required before updating details.',
            ], 403);
        }

        // Debug: Log the incoming data
        \Log::info('UpdateDetails - Validated data:', $validated);

        // Use all validated fields that exist in the student_nysc table
        // Remove fields that don't exist in student_nysc table structure
        $excludeFields = ['session', 'emergency_contact_title',
                         'emergency_contact_other_names', 'emergency_contact_email',
                         'blood_group', 'genotype', 'physical_condition',
                         'medical_condition', 'allergies'];

        $updateData = array_diff_key($validated, array_flip($excludeFields));
        $updateData['student_id'] = $student->id;

        // Debug: Log the filtered data
        \Log::info('UpdateDetails - Filtered data for update:', $updateData);

        // Update the record and mark as submitted
        $updateData['is_submitted'] = true;
        $nysc->update($updateData);

        // Debug: Log the updated record
        \Log::info('UpdateDetails - Updated NYSC record:', $nysc->fresh()->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Your details have been submitted successfully.',
            'data' => $nysc,
        ]);
    }

    /**
     * Confirm student's NYSC details (temporary save without submission)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmDetails(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();

        // Validate the request - handle all fields for the confirm page form
        $validated = $request->validate([
            // Student personal fields
            'fname' => 'required|string|max:100',
            'lname' => 'required|string|max:100',
            'mname' => 'nullable|string|max:100',
            'gender' => 'required|in:Male,Female',
            'dob' => 'required|date',
            'marital_status' => 'required|in:Single,Married,Divorced,Widowed',
            'religion' => 'nullable|string|max:100',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'address' => 'required|string',
            'state' => 'required|string|max:100',
            'lga' => 'required|string|max:100',
            'username' => 'nullable|string|max:255',
            'matric_no' => 'required|string|max:50',
            'department' => 'required|string|max:255',
            'course_study' => 'nullable|string|max:255',
            'level' => 'nullable|string|max:10',
            'graduation_year' => 'required|integer|min:2000|max:2030',
            'cgpa' => 'required|numeric|min:0|max:5',
            'jamb_no' => 'required|string|max:20',
            'study_mode' => 'required|string|max:100',

            // Payment details
            'payment_amount' => 'required|numeric|min:0',
        ]);

        // Generate unique session ID for this submission
        $sessionId = NyscTempSubmission::generateSessionId();

        // Prepare data for temporary storage
        $tempData = array_diff_key($validated, ['payment_amount' => '']);
        $tempData['student_id'] = $student->id;
        $tempData['session_id'] = $sessionId;
        $tempData['status'] = 'pending';

        // Delete any existing pending submissions for this student
        NyscTempSubmission::where('student_id', $student->id)
                         ->where('status', 'pending')
                         ->delete();

        // Create new temporary submission
        $tempSubmission = NyscTempSubmission::create($tempData);
        $tempSubmission->setExpirationTime();

        return response()->json([
            'success' => true,
            'message' => 'Details Confirmed. You can now proceed to payment.',
            'data' => [
                'session_id' => $sessionId,
                'payment_amount' => $validated['payment_amount'],
                'student_id' => $student->id,
                'expires_at' => $tempSubmission->expires_at
            ],
            'next_step' => 'payment'
        ]);
    }

    /**
     * Process payment and create payment record
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function processPayment(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();

        $validated = $request->validate([
            'student_nysc_id' => 'required|integer|exists:student_nysc,id',
            'amount' => 'required|numeric|min:0',
            'payment_reference' => 'required|string|max:255|unique:nysc_payments,payment_reference',
            'payment_method' => 'required|string|max:50',
            'transaction_id' => 'nullable|string|max:255',
            'payment_data' => 'nullable|array',
        ]);

        // Verify the NYSC record belongs to the authenticated student
        $nysc = StudentNysc::where('id', $validated['student_nysc_id'])
                          ->where('student_id', $student->id)
                          ->first();

        if (!$nysc) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found or does not belong to you.'
            ], 404);
        }

        try {
            DB::beginTransaction();

            // Create payment record
            $payment = NyscPayment::create([
                'student_nysc_id' => $validated['student_nysc_id'],
                'amount' => $validated['amount'],
                'payment_reference' => $validated['payment_reference'],
                'status' => 'successful', // Assuming payment verification is done externally
                'payment_method' => $validated['payment_method'],
                'transaction_id' => $validated['transaction_id'] ?? null,
                'payment_data' => $validated['payment_data'] ?? null,
                'payment_date' => now(),
            ]);

            // Mark NYSC record as submitted since payment is successful
            $nysc->update([
                'is_submitted' => true
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully and details submitted.',
                'data' => [
                    'payment' => $payment,
                    'nysc_record' => $nysc->fresh()
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment processing failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Submit student's NYSC details (final submission after payment)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitDetails(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();

        // Get NYSC record
        $nysc = StudentNysc::where('student_id', $student->id)->first();

        if (!$nysc) {
            return response()->json([
                'success' => false,
                'message' => 'Record not found. Please confirm your details first.'
            ], 404);
        }

        // Check if payment has been made using the new payment system
        if (!$nysc->hasSuccessfulPayment()) {
            return response()->json([
                'message' => 'Payment is required before submitting details.',
                'success' => false
            ], 403);
        }

        // If request has data, validate and update the record with new data
        if ($request->has('fname') || $request->has('firstName')) {
            $validated = $request->validate([
                // Personal Information
                'fname' => 'sometimes|string|max:255',
                'firstName' => 'sometimes|string|max:255', // Alternative field name
                'lname' => 'sometimes|string|max:255',
                'lastName' => 'sometimes|string|max:255', // Alternative field name
                'surname' => 'sometimes|string|max:255', // Alternative field name
                'mname' => 'sometimes|string|max:255|nullable',
                'middleName' => 'sometimes|string|max:255|nullable', // Alternative field name
                'gender' => 'sometimes|string|in:Male,Female',
                'dob' => 'sometimes|date',
                'dateOfBirth' => 'sometimes|date', // Alternative field name
                'marital_status' => 'sometimes|string|in:Single,Married,Divorced,Widowed',
                'phone' => 'sometimes|string|max:20',
                'phoneNumber' => 'sometimes|string|max:20', // Alternative field name
                'email' => 'sometimes|email|max:255',
                'username' => 'sometimes|email|max:255', // Alternative field name
                'address' => 'sometimes|string|max:500',
                'state' => 'sometimes|string|max:255',
                'lga' => 'sometimes|string|max:255',
                'religion' => 'sometimes|string|max:100|nullable',

                // Academic Information
                'matric_no' => 'sometimes|string|max:50',
                'matricNumber' => 'sometimes|string|max:50', // Alternative field name
                'course_of_study' => 'sometimes|string|max:255',
                'courseOfStudy' => 'sometimes|string|max:255', // Alternative field name
                'department' => 'sometimes|string|max:255',
                'graduation_year' => 'sometimes|integer|min:1900|max:' . (date('Y') + 10),
                'graduationYear' => 'sometimes|integer|min:1900|max:' . (date('Y') + 10), // Alternative field name
                'cgpa' => 'sometimes|numeric|min:0|max:5',
                'jamb_no' => 'sometimes|string|max:50|nullable',
                'jambNumber' => 'sometimes|string|max:50|nullable', // Alternative field name
                'study_mode' => 'sometimes|string|max:100',
                'studyMode' => 'sometimes|string|max:100', // Alternative field name
                'level' => 'sometimes|string|max:10|nullable',

                // Emergency Contact
                'emergency_contact_name' => 'sometimes|string|max:255|nullable',
                'emergencyContactName' => 'sometimes|string|max:255|nullable', // Alternative field name
                'emergency_contact_phone' => 'sometimes|string|max:20|nullable',
                'emergencyContactPhone' => 'sometimes|string|max:20|nullable', // Alternative field name
                'emergency_contact_relationship' => 'sometimes|string|max:100|nullable',
                'emergencyContactRelationship' => 'sometimes|string|max:100|nullable', // Alternative field name
                'emergency_contact_address' => 'sometimes|string|max:500|nullable',
                'emergencyContactAddress' => 'sometimes|string|max:500|nullable', // Alternative field name
            ]);

            // Map alternative field names to standard field names
            $mappedData = [];
            $fieldMappings = [
                'firstName' => 'fname',
                'lastName' => 'lname',
                'surname' => 'lname',
                'middleName' => 'mname',
                'dateOfBirth' => 'dob',
                'phoneNumber' => 'phone',
                'username' => 'email', // Map username to email
                'matricNumber' => 'matric_no',
                'courseOfStudy' => 'course_of_study',
                'graduationYear' => 'graduation_year',
                'jambNumber' => 'jamb_no',
                'studyMode' => 'study_mode',
                'emergencyContactName' => 'emergency_contact_name',
                'emergencyContactPhone' => 'emergency_contact_phone',
                'emergencyContactRelationship' => 'emergency_contact_relationship',
                'emergencyContactAddress' => 'emergency_contact_address',
            ];

            foreach ($validated as $key => $value) {
                $mappedKey = $fieldMappings[$key] ?? $key;
                $mappedData[$mappedKey] = $value;
            }

            // No additional field mappings needed

            // Remove fields that don't exist in student_nysc table
            $excludeFields = ['session', 'emergency_contact_title',
                             'emergency_contact_other_names', 'emergency_contact_email',
                             'blood_group', 'genotype', 'physical_condition',
                             'medical_condition', 'allergies', 'religion',
                             'emergency_contact_name', 'emergency_contact_phone',
                             'emergency_contact_relationship', 'emergency_contact_address'];

            $updateData = array_diff_key($mappedData, array_flip($excludeFields));
            $updateData['student_id'] = $student->id;
            $updateData['is_submitted'] = true;


            // Update the record with new data and mark as submitted
            $nysc->update($updateData);

            \Log::info('Student data submitted after payment', [
                'student_id' => $student->id,
                'nysc_id' => $nysc->id,
                'updated_fields' => array_keys($updateData)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Your NYSC details have been updated and submitted successfully.',
                'data' => $nysc->fresh()
            ]);
        }

        // If no data provided, just mark as submitted
        $nysc->update([
            'is_submitted' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your NYSC details have been submitted successfully.',
            'data' => $nysc
        ]);
    }

    /**
     * Get payment history for the authenticated student
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPaymentHistory(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = $request->user();

        // Get the student's NYSC record
        $studentNysc = StudentNysc::where('student_id', $student->id)->first();

        if (!$studentNysc) {
            return response()->json([
                'success' => false,
                'message' => 'No NYSC record found',
                'data' => [
                    'payments' => [],
                    'summary' => [
                        'total_paid' => 0,
                        'payment_status' => 'unpaid',
                        'last_payment_date' => null,
                        'successful_payments_count' => 0
                    ]
                ]
            ]);
        }

        // Get all payments for this NYSC record
        $payments = $studentNysc->payments()->orderBy('payment_date', 'desc')->get();

        // Calculate summary
        $successfulPayments = $payments->where('status', 'successful');
        $totalPaid = $successfulPayments->sum('amount');
        $paymentStatus = $successfulPayments->count() > 0 ? 'paid' : 'unpaid';
        $lastPaymentDate = $successfulPayments->first()?->payment_date;

        // Format payments for response
        $formattedPayments = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_reference' => $payment->payment_reference,
                'payment_date' => $payment->payment_date,
                'payment_method' => $payment->payment_method,
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'notes' => $payment->notes
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $formattedPayments,
                'summary' => [
                    'total_paid' => $totalPaid,
                    'payment_status' => $paymentStatus,
                    'last_payment_date' => $lastPaymentDate,
                    'successful_payments_count' => $successfulPayments->count(),
                    'total_payments_count' => $payments->count()
                ]
            ]
        ]);
    }

    /**
     * Get complete profile information for the authenticated student
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = $request->user();

        // Get the student's NYSC record with relationships
        $studentNysc = StudentNysc::with(['latestSuccessfulPayment'])
                                 ->where('student_id', $student->id)
                                 ->first();

        // Prepare basic student information
        $profile = [
            'basic_info' => [
                'student_id' => $student->id,
                'matric_no' => $student->matric_no,
                'email' => $student->email,
                'fname' => $student->fname,
                'lname' => $student->lname,
                'mname' => $student->mname,
                'phone' => $student->phone,
                'created_at' => $student->created_at,
                'updated_at' => $student->updated_at,
            ],
            'nysc_details' => null,
            'payment_info' => [
                'is_paid' => false,
                'payment_amount' => 0,
                'payment_date' => null,
                'payment_reference' => null,
                'payment_method' => null,
                'transaction_id' => null,
            ],
            'submission_status' => [
            'is_submitted' => false,
            'can_edit' => true,
        ],
            'documents' => [],
        ];

        if ($studentNysc) {
            $profile['nysc_details'] = [
                'personal_info' => [
                    'title' => $studentNysc->title,
                    'fname' => $studentNysc->fname,
                    'lname' => $studentNysc->lname,
                    'mname' => $studentNysc->mname,
                    'email' => $studentNysc->email,
                    'phone' => $studentNysc->phone,
                    'dob' => $studentNysc->dob,
                    'gender' => $studentNysc->gender,
                    'marital_status' => $studentNysc->marital_status,
                    'state' => $studentNysc->state,
                    'lga' => $studentNysc->lga,
                    'city' => $studentNysc->city,
                    'religion' => $studentNysc->religion,
                    'address' => $studentNysc->address,
                    'username' => $studentNysc->username,
                ],
                'academic_info' => [
                    'matric_no' => $studentNysc->matric_no,
                    'course_study' => $studentNysc->course_study,
                    'faculty' => $studentNysc->faculty,
                    'department' => $studentNysc->department,
                    'department_id' => $studentNysc->department_id,
                    'level' => $studentNysc->level,
                    'graduation_year' => $studentNysc->graduation_year,
                    'cgpa' => $studentNysc->cgpa,
                    'jamb_no' => $studentNysc->jamb_no,
                    'study_mode' => $studentNysc->study_mode,
                    'study_mode_id' => $studentNysc->study_mode_id,
                ],
                'emergency_contact' => [
                    'emergency_contact_name' => $studentNysc->emergency_contact_name,
                    'emergency_contact_phone' => $studentNysc->emergency_contact_phone,
                    'emergency_contact_relationship' => $studentNysc->emergency_contact_relationship,
                    'emergency_contact_address' => $studentNysc->emergency_contact_address,
                ],
            ];

            // Get payment info from the latest successful payment
            $latestPayment = $studentNysc->latestSuccessfulPayment;
            $profile['payment_info'] = [
                'is_paid' => $studentNysc->hasSuccessfulPayment(),
                'payment_amount' => $latestPayment ? $latestPayment->amount : null,
                'payment_date' => $latestPayment ? $latestPayment->payment_date : null,
                'payment_reference' => $latestPayment ? $latestPayment->payment_reference : null,
                'payment_method' => $latestPayment ? $latestPayment->payment_method : null,
                'transaction_id' => $latestPayment ? $latestPayment->transaction_id : null,
            ];

            $profile['submission_status'] = [
                'is_submitted' => $studentNysc->is_submitted,
                'can_edit' => !$studentNysc->is_submitted,
            ];
        }

        // Get document information (if documents exist)
        $documentsPath = storage_path('app/public/nysc/documents/' . $student->id);
        if (is_dir($documentsPath)) {
            $files = scandir($documentsPath);
            $documents = [];

            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $filePath = $documentsPath . '/' . $file;
                    $documents[] = [
                        'filename' => $file,
                        'size' => filesize($filePath),
                        'uploaded_at' => date('Y-m-d H:i:s', filemtime($filePath)),
                        'type' => pathinfo($file, PATHINFO_EXTENSION),
                    ];
                }
            }

            $profile['documents'] = $documents;
        }

        return response()->json([
            'success' => true,
            'profile' => $profile,
        ]);
    }

    /**
     * Update student profile information
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = $request->user();

        $request->validate([
            'firstName' => 'sometimes|string|max:255',
            'lastName' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $student->id,
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:500',
            'bio' => 'sometimes|string|max:1000',
            'matricNumber' => 'sometimes|string|max:50',
            'institution' => 'sometimes|string|max:255',
            'course' => 'sometimes|string|max:255',
        ]);

        try {
            // Update basic user information
            $updateData = [];
            if ($request->has('firstName')) {
                $updateData['fname'] = $request->firstName;
            }
            if ($request->has('lastName')) {
                $updateData['lname'] = $request->lastName;
            }
            if ($request->has('email')) {
                $updateData['email'] = $request->email;
            }
            if ($request->has('phone')) {
                $updateData['phone'] = $request->phone;
            }
            if ($request->has('address')) {
                $updateData['address'] = $request->address;
            }

            if (!empty($updateData)) {
                $student->update($updateData);
            }

            // Update NYSC record if it exists
            $studentNysc = StudentNysc::where('student_id', $student->id)->first();
            if ($studentNysc) {
                $nyscUpdateData = [];
                if ($request->has('bio')) {
                    $nyscUpdateData['bio'] = $request->bio;
                }
                if ($request->has('matricNumber')) {
                    $nyscUpdateData['matric_no'] = $request->matricNumber;
                }
                if ($request->has('institution')) {
                    $nyscUpdateData['institution'] = $request->institution;
                }
                if ($request->has('course')) {
                    $nyscUpdateData['course_study'] = $request->course;
                }

                if (!empty($nyscUpdateData)) {
                    $studentNysc->update($nyscUpdateData);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => $student->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all study modes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStudyModes(): \Illuminate\Http\JsonResponse
    {
        $studyModes = StudyMode::where('status', 1)->get(['id', 'mode']);

        return response()->json([
            'study_modes' => $studyModes,
        ]);
    }
}
