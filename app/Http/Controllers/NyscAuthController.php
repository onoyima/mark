<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\StudentNysc;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class NyscAuthController extends Controller
{
    public function __construct()
    {
        Log::info('NyscAuthController constructor hit');
        $this->middleware('auth:sanctum')->except(['login', 'adminLogin']);
    }
    /**
     * Authenticate a student for NYSC verification
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'identity' => 'required|string', // email or matric_no
            'password' => 'required|string',
        ]);

        // Try to find staff first (by email)
        $staff = null;
        if (filter_var($request->identity, FILTER_VALIDATE_EMAIL)) {
            $staff = Staff::where('email', $request->identity)->first();
        }

        // Try to find student (by email or matric number)
        $student = null;
        $academic = null;
        
        if (filter_var($request->identity, FILTER_VALIDATE_EMAIL)) {
            // Email login - check student email
            $student = Student::where('email', $request->identity)->first();
            if ($student) {
                $academic = StudentAcademic::where('student_id', $student->id)->first();
            }
        } else {
            // Matric number login
            $academic = StudentAcademic::where('matric_no', $request->identity)->first();
            $student = $academic ? Student::find($academic->student_id) : null;
        }

        // Process staff login
        if ($staff) {
            if (Hash::check($request->password, $staff->password)) {
                $token = $staff->createToken('nysc-token', ['nysc-admin'])->plainTextToken;

                Log::info('NYSC Login success', [
                    'email' => $request->identity,
                    'user_type' => 'staff',
                    'user_id' => $staff->id
                ]);

                // Load related data
                $staff->load(['contacts', 'workProfiles']);

                return response()->json([
                    'token' => $token,
                    'userType' => 'admin',
                    'user' => [
                        'id' => $staff->id,
                        'name' => $staff->fname ? $staff->fname . ' ' . $staff->lname : $staff->name,
                        'fname' => $staff->fname,
                        'lname' => $staff->lname,
                        'mname' => $staff->mname,
                        'maiden_name' => $staff->maiden_name,
                        'email' => $staff->email,
                        'p_email' => $staff->p_email,
                        'phone' => $staff->phone,
                        'gender' => $staff->gender,
                        'dob' => $staff->dob,
                        'title' => $staff->title,
                        'department' => $staff->department ?? null,
                        'country_id' => $staff->country_id,
                        'state_id' => $staff->state_id,
                        'lga_name' => $staff->lga_name,
                        'address' => $staff->address,
                        'city' => $staff->city,
                        'religion' => $staff->religion,
                        'marital_status' => $staff->marital_status,
                        'passport' => $staff->passport,
                        'signature' => $staff->signature,
                        'status' => $staff->status,
                        'contacts' => $staff->contacts,
                        // 'medical_info' => $staff->medicals->first(),
                        'work_profiles' => $staff->workProfiles,
                        // 'positions' => $staff->positions
                    ],
                    'message' => ($staff->fname ? $staff->fname : $staff->name) . ' logged in successfully.'
                ]);
            } else {
                Log::warning('NYSC Login failed: Incorrect password', [
                    'email' => $request->identity,
                    'user_type' => 'staff'
                ]);
                return response()->json([
                    'message' => 'Incorrect password.'
                ], 401);
            }
        }

        // Process student login

        if ($student) {
            if (Hash::check($request->password, $student->password)) {
                $token = $student->createToken('nysc-token', ['nysc-student'])->plainTextToken;

                Log::info('NYSC Login success', [
                    'matric_no' => $request->identity,
                    'user_type' => 'student',
                    'user_id' => $student->id
                ]);
                
                // Excel import functionality moved to admin panel

                // Load related data
                $student->load(['contacts', 'medicals']);
                $nyscData = StudentNysc::where('student_id', $student->id)->first();

                return response()->json([
                    'token' => $token,
                    'userType' => 'student',
                    'user' => [
                        'id' => $student->id,
                        'name' => $student->fname . ' ' . $student->lname,
                        'fname' => $student->fname,
                        'lname' => $student->lname,
                        'mname' => $student->mname,
                        'email' => $student->email,
                        'phone' => $student->phone,
                        'gender' => $student->gender,
                        'dob' => $student->dob,
                        'country_id' => $student->country_id,
                        'state_id' => $student->state_id,
                        'lga_name' => $student->lga_name,
                        'city' => $student->city,
                        'religion' => $student->religion,
                        'marital_status' => $student->marital_status,
                        'address' => $student->address,
                        'passport' => $student->passport ? base64_encode($student->passport) : null,
                        'signature' => $student->signature,
                        'hobbies' => $student->hobbies,
                        'username' => $student->username,
                        'matric_no' => $academic->matric_no,
                        'department' => $academic->department ?? null,
                        'level' => $academic->level ?? null,
                        'session' => $academic->session ?? null,
                        'status' => $student->status,
                        'contacts' => $student->contacts,
                        'medical_info' => $student->medicals->first(),
                        'nysc_data' => $nyscData
                    ],
                    'message' => $student->fname . ' logged in successfully.'
                ]);
            } else {
                Log::warning('NYSC Login failed: Incorrect password', [
                    'matric_no' => $request->identity,
                    'user_type' => 'student'
                ]);
                return response()->json([
                    'message' => 'Incorrect password.'
                ], 401);
            }
        }

        // Identity not found
        Log::warning('NYSC Login failed: Identity not found', ['identity' => $request->identity]);
        return response()->json([
            'message' => 'Identity not found.'
        ], 404);
    }

    /**
     * Verify NYSC authentication token and return user info
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if ($user instanceof Student) {
            // Student user
            $academic = StudentAcademic::where('student_id', $user->id)->first();
            $user->load(['contacts', 'medicals']);
            $nyscData = StudentNysc::where('student_id', $user->id)->first();

            return response()->json([
                'userType' => 'student',
                'student' => [
                    'id' => $user->id,
                    'name' => $user->fname . ' ' . $user->lname,
                    'fname' => $user->fname,
                    'lname' => $user->lname,
                    'mname' => $user->mname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'gender' => $user->gender,
                    'dob' => $user->dob,
                    'country_id' => $user->country_id,
                    'state_id' => $user->state_id,
                    'lga_name' => $user->lga_name,
                    'city' => $user->city,
                    'religion' => $user->religion,
                    'marital_status' => $user->marital_status,
                    'address' => $user->address,
                    'passport' => $user->passport ? base64_encode($user->passport) : null,
                    'signature' => $user->signature,
                    'hobbies' => $user->hobbies,
                    'username' => $user->username,
                    'matric_no' => $academic ? $academic->matric_no : null,
                    'department' => $academic ? $academic->department : null,
                    'level' => $academic ? $academic->level : null,
                    'session' => $academic ? $academic->session : null,
                    'status' => $user->status,
                    'contacts' => $user->contacts,
                    'medical_info' => $user->medicals->first(),
                    'nysc_data' => $nyscData
                ],
            ]);
        } elseif ($user instanceof Staff) {
            // Admin user
            $user->load(['contacts', 'workProfiles']);

            return response()->json([
                'userType' => 'admin',
                'admin' => [
                    'id' => $user->id,
                    'name' => $user->fname ? $user->fname . ' ' . $user->lname : $user->name,
                    'fname' => $user->fname,
                    'lname' => $user->lname,
                    'mname' => $user->mname,
                    'maiden_name' => $user->maiden_name,
                    'email' => $user->email,
                    'p_email' => $user->p_email,
                    'phone' => $user->phone,
                    'gender' => $user->gender,
                    'dob' => $user->dob,
                    'title' => $user->title,
                    'department' => $user->department ?? null,
                    'country_id' => $user->country_id,
                    'state_id' => $user->state_id,
                    'lga_name' => $user->lga_name,
                    'address' => $user->address,
                    'city' => $user->city,
                    'religion' => $user->religion,
                    'marital_status' => $user->marital_status,
                    'passport' => $user->passport ? base64_encode($user->passport) : null,
                    'signature' => $user->signature ? base64_encode($user->signature) : null,
                    'status' => $user->status,
                    'contacts' => $user->contacts,
                    'work_profiles' => $user->workProfiles,
                    'positions' => $user->positions
                ],
            ]);
        }
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    /**
     * Logout the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $user = $request->user();
            if ($user) {
                $user->currentAccessToken()->delete();
                Log::info('NYSC Logout success', [
                    'user_id' => $user->id,
                    'user_type' => get_class($user)
                ]);
                return response()->json([
                    'message' => 'Logout successful.'
                ]);
            } else {
                Log::warning('NYSC Logout failed: No authenticated user');
                return response()->json([
                    'message' => 'No authenticated user.'
                ], 401);
            }
        } catch (\Exception $e) {
            Log::error('NYSC Logout failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Logout failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
