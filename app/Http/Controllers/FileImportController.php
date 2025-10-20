<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use App\Services\FileProcessingService;
use App\Jobs\ProcessFileImportJob;
use App\Models\StudentNysc;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;

class FileImportController extends Controller
{
    protected $fileProcessingService;

    public function __construct(FileProcessingService $fileProcessingService)
    {
        $this->fileProcessingService = $fileProcessingService;
    }

    /**
     * Upload and process file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadFile(Request $request): JsonResponse
    {
        try {
            // Validate the uploaded file
            $validator = Validator::make($request->all(), [
                'file' => [
                    'required',
                    'file',
                    'mimes:docx,doc,xlsx,xls,csv,pdf',
                    'max:20480' // 20MB max
                ]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'File validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');
            $user = Auth::user();
            
            // Generate unique session ID and filename
            $sessionId = Str::uuid()->toString();
            $filename = $sessionId . '_' . time() . '.' . $file->getClientOriginalExtension();
            $uploadPath = 'imports/' . date('Y/m/d');
            
            // Ensure directory exists
            $fullUploadPath = storage_path('app/' . $uploadPath);
            if (!file_exists($fullUploadPath)) {
                mkdir($fullUploadPath, 0755, true);
            }
            
            // Move uploaded file
            $filePath = $fullUploadPath . '/' . $filename;
            $file->move($fullUploadPath, $filename);
            
            Log::info('File uploaded for processing', [
                'session_id' => $sessionId,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'user_id' => $user->id ?? null
            ]);

            // Initialize job status
            Cache::put("file_import_status_{$sessionId}", [
                'status' => 'queued',
                'progress' => 0,
                'message' => 'File uploaded, processing queued...',
                'queued_at' => now()
            ], now()->addHours(2));

            // Dispatch job for background processing
            ProcessFileImportJob::dispatch(
                $filePath,
                $file->getClientOriginalName(),
                $sessionId,
                $user->id ?? 0
            );

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully and processing started',
                'session_id' => $sessionId,
                'status' => 'queued'
            ]);

        } catch (\Exception $e) {
            Log::error('Error in file upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while uploading the file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get processing status
     *
     * @param string $sessionId
     * @return JsonResponse
     */
    public function getProcessingStatus(string $sessionId): JsonResponse
    {
        try {
            $status = Cache::get("file_import_status_{$sessionId}");
            
            if (!$status) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found or expired'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'status' => $status
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving processing status', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving processing status'
            ], 500);
        }
    }

    /**
     * Get review data for a session
     *
     * @param string $sessionId
     * @return JsonResponse
     */
    public function getReviewData(string $sessionId): JsonResponse
    {
        try {
            $sessionData = Cache::get("file_import_session_{$sessionId}");
            
            if (!$sessionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found or expired'
                ], 404);
            }

            // Check if session has expired
            if (now()->gt($sessionData['expires_at'])) {
                Cache::forget("file_import_session_{$sessionId}");
                
                // Clean up temp file
                if (isset($sessionData['file_path']) && file_exists($sessionData['file_path'])) {
                    unlink($sessionData['file_path']);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Session has expired'
                ], 410);
            }

            return response()->json([
                'success' => true,
                'session_id' => $sessionId,
                'original_filename' => $sessionData['original_filename'],
                'summary' => $sessionData['summary'],
                'review_data' => $sessionData['review_data'],
                'file_type' => $sessionData['file_type'],
                'expires_at' => $sessionData['expires_at']
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving review data', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving review data'
            ], 500);
        }
    }

    /**
     * Apply approved updates
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function approveUpdates(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'session_id' => 'required|string',
                'approvals' => 'required|array',
                'approvals.*.student_id' => 'required|integer',
                'approvals.*.matric_no' => 'required|string',
                'approvals.*.proposed_class_of_degree' => 'required|string',
                'approvals.*.approved' => 'required|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $sessionId = $request->input('session_id');
            $approvals = $request->input('approvals');

            // Verify session exists
            $sessionData = Cache::get("file_import_session_{$sessionId}");
            if (!$sessionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found or expired'
                ], 404);
            }

            // Apply approved updates
            $result = $this->fileProcessingService->applyApprovedUpdates($approvals);

            // Clean up session and temp file
            Cache::forget("file_import_session_{$sessionId}");
            Cache::forget("file_import_status_{$sessionId}");
            
            if (isset($sessionData['file_path']) && file_exists($sessionData['file_path'])) {
                unlink($sessionData['file_path']);
            }

            Log::info('File import completed', [
                'session_id' => $sessionId,
                'result' => $result
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Updates applied successfully',
                'result' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Error applying updates', [
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
     * Export complete student NYSC data to Excel
     *
     * @return Response
     */
    public function exportStudentData(): Response
    {
        try {
            Log::info('Starting student data export');

            // Get all student NYSC records with required fields
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

            // Prepare data for export
            $exportData = [];
            $exportData[] = [
                'Matric No',
                'First Name',
                'Middle Name',
                'Last Name',
                'Phone',
                'State',
                'Class of Degree',
                'Date of Birth',
                'Graduation Year',
                'Gender',
                'Marital Status',
                'JAMB No',
                'Course of Study',
                'Study Mode'
            ];

            foreach ($students as $student) {
                $exportData[] = [
                    $student->matric_no ?? '',
                    $student->fname ?? '',
                    $student->mname ?? '',
                    $student->lname ?? '',
                    $student->phone ?? '',
                    $student->state ?? '',
                    $student->class_of_degree ?? '',
                    $student->dob ? $student->dob->format('Y-m-d') : '',
                    $student->graduation_year ?? '',
                    $student->gender ?? '',
                    $student->marital_status ?? '',
                    $student->jamb_no ?? '',
                    $student->course_study ?? '',
                    $student->study_mode ?? ''
                ];
            }

            // Create Excel file
            $filename = 'student_nysc_data_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            return Excel::download(new class($exportData) implements \Maatwebsite\Excel\Concerns\FromArray {
                private $data;
                
                public function __construct($data) {
                    $this->data = $data;
                }
                
                public function array(): array {
                    return $this->data;
                }
            }, $filename);

        } catch (\Exception $e) {
            Log::error('Error exporting student data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error exporting data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get import statistics
     *
     * @return JsonResponse
     */
    public function getImportStats(): JsonResponse
    {
        try {
            $stats = [
                'total_students' => StudentNysc::count(),
                'students_with_class_degree' => StudentNysc::whereNotNull('class_of_degree')->count(),
                'students_without_class_degree' => StudentNysc::whereNull('class_of_degree')->count(),
                'class_degree_distribution' => StudentNysc::selectRaw('class_of_degree, COUNT(*) as count')
                    ->whereNotNull('class_of_degree')
                    ->groupBy('class_of_degree')
                    ->get()
                    ->pluck('count', 'class_of_degree')
                    ->toArray(),
                'supported_formats' => $this->fileProcessingService->getSupportedFormats()
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting import stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving statistics'
            ], 500);
        }
    }

    /**
     * Get recent import history
     *
     * @return JsonResponse
     */
    public function getImportHistory(): JsonResponse
    {
        try {
            // This would typically come from a database table
            // For now, we'll return empty array
            $history = [];

            return response()->json([
                'success' => true,
                'history' => $history
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting import history', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error retrieving import history'
            ], 500);
        }
    }
}