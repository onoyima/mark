<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use App\Services\DocxImportService;
use App\Models\StudentNysc;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use App\Exports\StudentNyscDataExport;
use App\Exports\StudentNullDegreeExport;

class NyscDocxImportController extends Controller
{
    protected $docxImportService;

    public function __construct(DocxImportService $docxImportService)
    {
        $this->docxImportService = $docxImportService;
    }

    /**
     * Test endpoint to verify route is working
     */
    public function test(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            // Check temp directory
            $tempDir = storage_path('app/temp/docx_imports');
            $tempDirExists = file_exists($tempDir);
            $tempDirWritable = is_writable(dirname($tempDir));
            
            // Check if required classes exist
            $phpWordExists = class_exists('PhpOffice\PhpWord\IOFactory');
            $excelExists = class_exists('Maatwebsite\Excel\Facades\Excel');
            
            return response()->json([
                'success' => true,
                'message' => 'DOCX Import Controller is working',
                'timestamp' => now(),
                'user_id' => $user ? $user->id : null,
                'authenticated' => $user !== null,
                'temp_dir_exists' => $tempDirExists,
                'temp_dir_writable' => $tempDirWritable,
                'temp_dir_path' => $tempDir,
                'phpword_available' => $phpWordExists,
                'excel_available' => $excelExists
            ]);
        } catch (\Exception $e) {
            Log::error('Test endpoint error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload and process DOCX file (simplified for testing)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadDocx(Request $request): JsonResponse
    {
        try {
            Log::info('DOCX upload request received', [
                'has_file' => $request->hasFile('docx_file'),
                'files' => $request->allFiles(),
                'user' => auth()->user() ? auth()->user()->id : 'not authenticated'
            ]);

            // Validate the uploaded file
            $validator = Validator::make($request->all(), [
                'docx_file' => [
                    'required',
                    'file',
                    'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/octet-stream',
                    'max:10240' // 10MB max
                ]
            ]);

            if ($validator->fails()) {
                Log::warning('File validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false,
                    'message' => 'File validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('docx_file');
            
            Log::info('File details', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension()
            ]);
            
            // Generate unique filename and store temporarily
            $filename = Str::uuid() . '_' . time() . '.docx';
            $tempPath = storage_path('app/temp/docx_imports/' . $filename);
            
            // Ensure directory exists
            $directory = dirname($tempPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
                Log::info('Created directory', ['directory' => $directory]);
            }
            
            // Move uploaded file to temp location
            $file->move($directory, $filename);
            
            Log::info('File moved successfully', [
                'temp_path' => $tempPath,
                'file_exists' => file_exists($tempPath)
            ]);
            
            Log::info('DOCX file uploaded', [
                'original_name' => $file->getClientOriginalName(),
                'temp_path' => $tempPath,
                'file_size' => filesize($tempPath)
            ]);

            // Process the DOCX file
            try {
                $result = $this->docxImportService->processDocxFile($tempPath);
                
                if (!$result['success']) {
                    // Clean up temp file on error
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    
                    return response()->json([
                        'success' => false,
                        'message' => $result['error']
                    ], 500);
                }
            } catch (\Exception $e) {
                Log::error('DOCX processing failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Clean up temp file on error
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process DOCX file: ' . $e->getMessage()
                ], 500);
            }

            // Store session data in cache for review
            $sessionId = $result['session_id'];
            $sessionData = [
                'session_id' => $sessionId,
                'file_path' => $tempPath,
                'original_filename' => $file->getClientOriginalName(),
                'review_data' => $result['review_data'],
                'summary' => $result['summary'],
                'created_at' => now(),
                'expires_at' => now()->addHours(6) // 6 hour expiry
            ];
            
            cache()->put("docx_import_session_{$sessionId}", $sessionData, now()->addHours(6));
            
            Log::info('DOCX import session created', [
                'session_id' => $sessionId,
                'summary' => $result['summary']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File processed successfully',
                'session_id' => $sessionId,
                'summary' => $result['summary']
            ]);

        } catch (\Exception $e) {
            Log::error('Error in DOCX upload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the file: ' . $e->getMessage()
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
            $sessionData = cache()->get("docx_import_session_{$sessionId}");
            
            if (!$sessionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found or expired'
                ], 404);
            }

            // Check if session has expired
            if (now()->gt($sessionData['expires_at'])) {
                cache()->forget("docx_import_session_{$sessionId}");
                
                // Clean up temp file
                if (isset($sessionData['file_path']) && file_exists($sessionData['file_path'])) {
                    try {
                        usleep(100000); // 100ms delay
                        unlink($sessionData['file_path']);
                        Log::info('Expired session file cleaned up', ['file_path' => $sessionData['file_path']]);
                    } catch (\Exception $fileError) {
                        Log::warning('Failed to clean up expired session file', [
                            'file_path' => $sessionData['file_path'],
                            'error' => $fileError->getMessage()
                        ]);
                    }
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
            $sessionData = cache()->get("docx_import_session_{$sessionId}");
            if (!$sessionData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found or expired'
                ], 404);
            }

            // Apply approved updates
            $result = $this->docxImportService->applyApprovedUpdates($approvals);

            // Clean up session and temp file
            cache()->forget("docx_import_session_{$sessionId}");
            if (isset($sessionData['file_path']) && file_exists($sessionData['file_path'])) {
                try {
                    // Add a small delay to ensure file handles are released
                    usleep(100000); // 100ms delay
                    unlink($sessionData['file_path']);
                    Log::info('Temporary file cleaned up', ['file_path' => $sessionData['file_path']]);
                } catch (\Exception $fileError) {
                    // Log the error but don't fail the entire operation
                    Log::warning('Failed to clean up temporary file', [
                        'file_path' => $sessionData['file_path'],
                        'error' => $fileError->getMessage()
                    ]);
                }
            }

            Log::info('DOCX import completed', [
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
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportStudentData()
    {
        try {
            Log::info('Starting student data export');

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

            // Prepare data for export with exact database values
            $exportData = [];
            foreach ($students as $student) {
                $exportData[] = [
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
                ];
            }

            // Create Excel file
            $filename = 'student_nysc_data_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            return Excel::download(new StudentNyscDataExport($exportData), $filename);

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
     * Get students with NULL class_of_degree and match with GRADUANDS.docx
     *
     * @return JsonResponse
     */
    public function getGraduandsMatches(): JsonResponse
    {
        try {
            Log::info('Starting GRADUANDS matching process');
            
            $filePath = storage_path('app/GRADUANDS.docx');
            
            // Check if file exists
            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'GRADUANDS.docx file not found. Please ensure the file exists in storage/app/GRADUANDS.docx'
                ], 404);
            }

            // Process the DOCX file to extract data
            $result = $this->docxImportService->processDocxFile($filePath);
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process GRADUANDS.docx: ' . $result['error']
                ], 500);
            }

            // Get students with NULL class_of_degree
            $studentsWithNullDegree = StudentNysc::whereNull('class_of_degree')
                ->select(['id', 'matric_no', 'fname', 'mname', 'lname', 'class_of_degree'])
                ->get();

            // Match extracted data with students who have NULL class_of_degree
            $matches = [];
            foreach ($result['review_data'] as $extractedData) {
                $student = $studentsWithNullDegree->firstWhere('matric_no', strtoupper($extractedData['matric_no']));
                
                if ($student) {
                    $matches[] = [
                        'student_id' => $student->id,
                        'matric_no' => $student->matric_no,
                        'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                        'current_class_of_degree' => null, // Always null since we filtered for null
                        'proposed_class_of_degree' => $extractedData['proposed_class_of_degree'],
                        'needs_update' => true, // Always true since current is null
                        'approved' => false,
                        'source' => $extractedData['source'] ?? 'docx',
                        'row_number' => $extractedData['row_number'] ?? null
                    ];
                }
            }

            $summary = [
                'total_students_with_null_degree' => $studentsWithNullDegree->count(),
                'total_extracted_from_docx' => count($result['review_data']),
                'total_matches_found' => count($matches),
                'file_last_modified' => date('Y-m-d H:i:s', filemtime($filePath))
            ];

            Log::info('GRADUANDS matching completed', $summary);

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'matches' => $matches,
                'message' => count($matches) > 0 
                    ? "Found " . count($matches) . " students with NULL class_of_degree that can be updated"
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
     * @return JsonResponse
     */
    public function applyGraduandsUpdates(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
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

                        // Only update if class_of_degree is still NULL
                        if ($student->class_of_degree === null) {
                            $student->class_of_degree = $update['proposed_class_of_degree'];
                            $student->save();
                            $updatedCount++;
                            
                            Log::info('Student class_of_degree updated', [
                                'student_id' => $student->id,
                                'matric_no' => $student->matric_no,
                                'new_value' => $update['proposed_class_of_degree']
                            ]);
                        } else {
                            Log::info('Student already has class_of_degree, skipping', [
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
     * Export students with NULL class_of_degree to Excel
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportStudentsWithNullDegree()
    {
        try {
            Log::info('Starting export of students with NULL class_of_degree');

            // Get students with NULL class_of_degree
            $students = StudentNysc::whereNull('class_of_degree')
                ->select([
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

            // Prepare data for export with exact database values
            $exportData = [];
            foreach ($students as $student) {
                $exportData[] = [
                    $student->matric_no,
                    $student->fname,
                    $student->mname,
                    $student->lname,
                    $student->phone,
                    $student->state,
                    $student->class_of_degree, // This will be NULL
                    $student->dob,
                    $student->graduation_year,
                    $student->gender,
                    $student->marital_status,
                    $student->jamb_no,
                    $student->course_study,
                    $student->study_mode
                ];
            }

            // Create Excel file with specific filename for null degree records
            $filename = 'students_null_class_of_degree_' . date('Y-m-d_H-i-s') . '.xlsx';
            
            Log::info('Export completed', [
                'total_records' => count($exportData),
                'filename' => $filename
            ]);
            
            return Excel::download(new StudentNullDegreeExport($exportData), $filename);

        } catch (\Exception $e) {
            Log::error('Error exporting students with null class_of_degree', [
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
                    ->toArray()
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
}