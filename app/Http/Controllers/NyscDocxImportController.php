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
    public function getGraduandsMatches(Request $request): JsonResponse
    {
        try {
            Log::info('Starting GRADUANDS matching process');
            
            $storageDir = storage_path('app');
            $requestedFile = $request->query('file');
            $availableFiles = [];
            foreach (glob($storageDir . '/GRADUANDS*.docx') as $f) {
                $availableFiles[] = [
                    'name' => basename($f),
                    'size' => $this->formatBytes(filesize($f)),
                    'modified' => date('Y-m-d H:i:s', filemtime($f))
                ];
            }
            usort($availableFiles, function($a, $b){ return strcmp($a['name'], $b['name']); });
            $currentFileName = $requestedFile ?: 'GRADUANDS.docx';
            $currentFilePath = file_exists($storageDir . '/' . $currentFileName) ? ($storageDir . '/' . $currentFileName) : null;
            if (!$currentFilePath && !empty($availableFiles)) {
                $currentFileName = $availableFiles[0]['name'];
                $currentFilePath = $storageDir . '/' . $currentFileName;
            }
            if (!$currentFilePath) {
                return response()->json([
                    'success' => false,
                    'summary' => [
                        'total_students_with_null_degree' => 0,
                        'total_extracted_from_docx' => 0,
                        'total_matches_found' => 0,
                        'exact_matches' => 0,
                        'similar_matches' => 0,
                        'total_unmatched' => 0,
                        'current_file' => null,
                        'available_files' => $availableFiles,
                        'file_last_modified' => null
                    ],
                    'matches' => [],
                    'unmatched' => [],
                    'message' => 'No GRADUANDS*.docx file found in storage/app'
                ]);
            }

            // Preflight: verify PhpWord is available to avoid runtime fatal errors
            $phpWordAvailable = class_exists('PhpOffice\\PhpWord\\IOFactory');
            if (!$phpWordAvailable) {
                return response()->json([
                    'success' => false,
                    'summary' => [
                        'total_students_with_null_degree' => 0,
                        'total_extracted_from_docx' => 0,
                        'total_matches_found' => 0,
                        'exact_matches' => 0,
                        'similar_matches' => 0,
                        'total_unmatched' => 0,
                        'current_file' => $currentFileName,
                        'available_files' => $availableFiles,
                        'file_last_modified' => date('Y-m-d H:i:s', filemtime($currentFilePath))
                    ],
                    'matches' => [],
                    'unmatched' => [],
                    'message' => 'PhpWord library not available on server'
                ]);
            }

            // Process the DOCX file to extract data
            $result = $this->docxImportService->processDocxFile($currentFilePath);
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'summary' => [
                        'total_students_with_null_degree' => 0,
                        'total_extracted_from_docx' => 0,
                        'total_matches_found' => 0,
                        'exact_matches' => 0,
                        'similar_matches' => 0,
                        'total_unmatched' => 0,
                        'current_file' => $currentFileName,
                        'available_files' => $availableFiles,
                        'file_last_modified' => date('Y-m-d H:i:s', filemtime($currentFilePath))
                    ],
                    'matches' => [],
                    'unmatched' => [],
                    'message' => 'Failed to process GRADUANDS file: ' . $result['error']
                ]);
            }

            // Get ALL students for comprehensive matching
            $allStudents = StudentNysc::select(['id', 'matric_no', 'fname', 'mname', 'lname', 'class_of_degree'])
                ->get();

            // Create a lookup array for faster matching
            $studentLookup = [];
            foreach ($allStudents as $student) {
                $studentLookup[strtoupper($student->matric_no)] = $student;
            }

            // Match extracted data with ALL students (exact and fuzzy matches)
            $exactMatches = [];
            $similarMatches = [];
            $unmatched = [];

            foreach ($result['review_data'] as $extractedData) {
                $graduandsMatric = strtoupper($extractedData['matric_no']);
                $matched = false;
                
                // First try exact match
                if (isset($studentLookup[$graduandsMatric])) {
                    $student = $studentLookup[$graduandsMatric];
                    $exactMatches[] = [
                        'student_id' => $student->id,
                        'matric_no' => $student->matric_no,
                        'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                        'current_class_of_degree' => $student->class_of_degree,
                        'proposed_class_of_degree' => $extractedData['proposed_class_of_degree'],
                        'needs_update' => ($student->class_of_degree === null || $student->class_of_degree === '') || $student->class_of_degree !== $extractedData['proposed_class_of_degree'],
                        'approved' => false,
                        'source' => $extractedData['source'] ?? 'docx',
                        'row_number' => $extractedData['row_number'] ?? null,
                        'match_type' => 'exact'
                    ];
                    $matched = true;
                } else {
                    // Try fuzzy matching for similar matric numbers
                    $similarMatric = $this->findSimilarMatricNumber($graduandsMatric, array_keys($studentLookup));
                    
                    if ($similarMatric) {
                        $student = $studentLookup[$similarMatric];
                        $similarMatches[] = [
                            'student_id' => $student->id,
                            'matric_no' => $student->matric_no,
                            'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                            'current_class_of_degree' => $student->class_of_degree,
                            'proposed_class_of_degree' => $extractedData['proposed_class_of_degree'],
                            'needs_update' => ($student->class_of_degree === null || $student->class_of_degree === '') || $student->class_of_degree !== $extractedData['proposed_class_of_degree'],
                            'approved' => false,
                            'source' => $extractedData['source'] ?? 'docx',
                            'row_number' => $extractedData['row_number'] ?? null,
                            'match_type' => 'similar',
                            'graduands_matric' => $extractedData['matric_no'],
                            'similarity_type' => $this->getSimilarityType($graduandsMatric, $similarMatric)
                        ];
                        $matched = true;
                    }
                }
                
                // If no match found, add to unmatched
                if (!$matched) {
                    $unmatched[] = [
                        'docx_matric' => $extractedData['matric_no'],
                        'normalized_matric' => $graduandsMatric,
                        'class_of_degree' => $extractedData['proposed_class_of_degree'],
                        'student_name' => $extractedData['student_name'] ?? 'Unknown'
                    ];
                }
            }

            // Combine all matches
            $allMatches = array_merge($exactMatches, $similarMatches);
            
            // Count students with NULL class_of_degree for reference
            $studentsWithNullDegree = $allStudents->filter(function($s){ return $s->class_of_degree === null || $s->class_of_degree === ''; })->count();

            $summary = [
                'total_students_with_null_degree' => $studentsWithNullDegree,
                'total_extracted_from_docx' => count($result['review_data']),
                'total_matches_found' => count($allMatches),
                'exact_matches' => count($exactMatches),
                'similar_matches' => count($similarMatches),
                'total_unmatched' => count($unmatched),
                'current_file' => $currentFileName,
                'available_files' => $availableFiles,
                'file_last_modified' => date('Y-m-d H:i:s', filemtime($currentFilePath))
            ];

            Log::info('GRADUANDS matching completed', [
                'total_graduands' => count($result['review_data']),
                'exact_matches' => count($exactMatches),
                'similar_matches' => count($similarMatches),
                'unmatched' => count($unmatched)
            ]);

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'matches' => $allMatches,
                'unmatched' => $unmatched,
                'message' => count($allMatches) > 0 
                    ? "Found " . count($allMatches) . " matches (" . count($exactMatches) . " exact, " . count($similarMatches) . " similar)"
                    : "No matches found"
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in GRADUANDS matching', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Attempt to provide context for frontend rendering
            $storageDir = storage_path('app');
            $availableFiles = [];
            foreach (glob($storageDir . '/GRADUANDS*.docx') as $f) {
                $availableFiles[] = [
                    'name' => basename($f),
                    'size' => $this->formatBytes(filesize($f)),
                    'modified' => date('Y-m-d H:i:s', filemtime($f))
                ];
            }

            return response()->json([
                'success' => false,
                'summary' => [
                    'total_students_with_null_degree' => 0,
                    'total_extracted_from_docx' => 0,
                    'total_matches_found' => 0,
                    'exact_matches' => 0,
                    'similar_matches' => 0,
                    'total_unmatched' => 0,
                    'current_file' => null,
                    'available_files' => $availableFiles,
                    'file_last_modified' => null
                ],
                'matches' => [],
                'unmatched' => [],
                'message' => 'Error processing GRADUANDS data: ' . $e->getMessage()
            ]);
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
            $skippedCount = 0;
            $notApprovedCount = 0;
            $errors = [];

            // Log the incoming data for debugging
            Log::info('Applying GRADUANDS updates', [
                'total_updates' => count($updates),
                'approved_updates' => count(array_filter($updates, fn($u) => $u['approved'] ?? false))
            ]);

            try {
                \DB::beginTransaction();

                foreach ($updates as $update) {
                    if (!($update['approved'] ?? false)) {
                        $notApprovedCount++;
                        continue; // Skip non-approved updates
                    }

                    try {
                        $student = StudentNysc::find($update['student_id']);
                        
                        if (!$student) {
                            $errorCount++;
                            $errors[] = "Student not found: {$update['matric_no']}";
                            Log::warning('Student not found during update', [
                                'student_id' => $update['student_id'],
                                'matric_no' => $update['matric_no']
                            ]);
                            continue;
                        }

                        // Only update if class_of_degree is NULL or empty
                        if ($student->class_of_degree === null || $student->class_of_degree === '') {
                            $student->class_of_degree = $update['proposed_class_of_degree'];
                            $student->save();
                            $updatedCount++;
                            
                            Log::info('Student class_of_degree updated', [
                                'student_id' => $student->id,
                                'matric_no' => $student->matric_no,
                                'new_value' => $update['proposed_class_of_degree']
                            ]);
                        } else {
                            $skippedCount++;
                            Log::info('Student already has class_of_degree, skipping', [
                                'student_id' => $student->id,
                                'matric_no' => $student->matric_no,
                                'existing_value' => $student->class_of_degree,
                                'existing_value_length' => strlen($student->class_of_degree)
                            ]);
                        }

                    } catch (\Exception $e) {
                        $errorCount++;
                        $errors[] = "Error updating {$update['matric_no']}: " . $e->getMessage();
                        Log::error('Error updating student', [
                            'matric_no' => $update['matric_no'],
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }

                \DB::commit();

                Log::info('Batch update completed', [
                    'total_processed' => count($updates),
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'not_approved_count' => $notApprovedCount,
                    'error_count' => $errorCount
                ]);

                $message = "Updates applied successfully! {$updatedCount} records updated";
                if ($skippedCount > 0) {
                    $message .= ", {$skippedCount} records skipped (already have class of degree)";
                }
                if ($notApprovedCount > 0) {
                    $message .= ", {$notApprovedCount} records not approved";
                }
                if ($errorCount > 0) {
                    $message .= ", {$errorCount} records had errors";
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'result' => [
                        'total_processed' => count($updates),
                        'updated_count' => $updatedCount,
                        'skipped_count' => $skippedCount,
                        'not_approved_count' => $notApprovedCount,
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

            // Get students with NULL or empty class_of_degree
            $students = StudentNysc::where(function($query) {
                $query->whereNull('class_of_degree')->orWhere('class_of_degree', '');
            })
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
                'students_without_class_degree' => StudentNysc::where(function($query) {
                    $query->whereNull('class_of_degree')->orWhere('class_of_degree', '');
                })->count(),
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

    /**
     * Get complete GRADUANDS file analysis with all records
     *
     * @return JsonResponse
     */
    public function getCompleteFileAnalysis(): JsonResponse
    {
        try {
            Log::info('Starting complete GRADUANDS file analysis');
           
            $filePath = storage_path('app/GRADUANDS.docx');
            
            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'GRADUANDS.docx file not found. Please ensure the file exists'
                ]);
            }

            // Process the DOCX file to extract data
            $result = $this->docxImportService->processDocxFile($filePath);
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process GRADUANDS.docx: ' . $result['error']
                ], 500);
            }

            // Get ALL students for comprehensive matching
            $allStudents = StudentNysc::select(['id', 'matric_no', 'fname', 'mname', 'lname', 'class_of_degree'])
                ->get();
            

            // Create a lookup array for faster matching
            $studentLookup = [];
            foreach ($allStudents as $student) {
                $studentLookup[strtoupper($student->matric_no)] = $student;
            }

            // Prepare complete file data with match status
            $completeFileData = [];
            $matchedCount = 0;
            $unmatchedCount = 0;

            foreach ($result['review_data'] as $index => $extractedData) {
                $graduandsMatric = strtoupper($extractedData['matric_no']);
                $isMatched = false;
                $matchType = 'none';
                $dbMatricNo = null;
                $similarityType = null;

                // Check for exact match
                if (isset($studentLookup[$graduandsMatric])) {
                    $isMatched = true;
                    $matchType = 'exact';
                    $dbMatricNo = $studentLookup[$graduandsMatric]->matric_no;
                    $matchedCount++;
                } else {
                    // Check for similar match
                    $similarMatric = $this->findSimilarMatricNumber($graduandsMatric, array_keys($studentLookup));
                    if ($similarMatric) {
                        $isMatched = true;
                        $matchType = 'similar';
                        $dbMatricNo = $studentLookup[$similarMatric]->matric_no;
                        $similarityType = $this->getSimilarityType($graduandsMatric, $similarMatric);
                        $matchedCount++;
                    } else {
                        $unmatchedCount++;
                    }
                }

                $completeFileData[] = [
                    'row_number' => $index + 1,
                    'matric_no' => $extractedData['matric_no'],
                    'student_name' => $extractedData['student_name'] ?? 'Unknown',
                    'class_of_degree' => $extractedData['proposed_class_of_degree'],
                    'source' => 'docx',
                    'original_row' => $extractedData['row_number'] ?? null,
                    'is_matched' => $isMatched,
                    'match_type' => $matchType,
                    'db_matric_no' => $dbMatricNo,
                    'similarity_type' => $similarityType
                ];
            }

            // Count students with NULL or empty class_of_degree for reference
            $studentsWithNullDegree = StudentNysc::where(function($query) {
                $query->whereNull('class_of_degree')->orWhere('class_of_degree', '');
            })->count();

            $summary = [
                'total_records_in_file' => count($result['review_data']),
                'total_matched_with_db' => $matchedCount,
                'total_unmatched_from_file' => $unmatchedCount,
                'match_percentage' => count($result['review_data']) > 0 ? round(($matchedCount / count($result['review_data'])) * 100, 2) : 0,
                'students_with_null_degree_in_db' => $studentsWithNullDegree,
                'total_students_in_db' => $allStudents->count(),
                'file_last_modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                'file_size' => $this->formatBytes(filesize($filePath))
            ];

            Log::info('Complete file analysis completed', [
                'total_in_file' => count($result['review_data']),
                'matched' => $matchedCount,
                'unmatched' => $unmatchedCount,
                'match_rate' => $summary['match_percentage']
            ]);

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'complete_file_data' => $completeFileData,
                'source' => 'docx'
            ]);

        } catch (\Exception $e) {
            Log::error('Error in complete file analysis', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error analyzing GRADUANDS file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }

    /**
     * Get comprehensive data analysis and statistics
     *
     * @return JsonResponse
     */
    public function getDataAnalysis(): JsonResponse
    {
        try {
            Log::info('Starting comprehensive data analysis');
            
            // Database statistics
            $totalStudentsInDb = StudentNysc::count();
            $studentsWithNullDegree = StudentNysc::where(function($query) {
                $query->whereNull('class_of_degree')->orWhere('class_of_degree', '');
            })->count();
            $studentsWithClassDegree = StudentNysc::where(function($query) {
                $query->whereNotNull('class_of_degree')->where('class_of_degree', '!=', '');
            })->count();
            
            // Get detailed null degree students
            $nullDegreeStudents = StudentNysc::where(function($query) {
                $query->whereNull('class_of_degree')->orWhere('class_of_degree', '');
            })
                ->select(['id', 'matric_no', 'fname', 'mname', 'lname', 'department'])
                ->get()
                ->toArray();
            
            // GRADUANDS file statistics
            $filePath = storage_path('app/GRADUANDS.docx');
            $graduandsFileExists = file_exists($filePath);
            $graduandsLastModified = $graduandsFileExists ? date('Y-m-d H:i:s', filemtime($filePath)) : null;
            
            $totalInGraduandsFile = 0;
            $matchedRecords = 0;
            $unmatched_graduands = [];
            
            if ($graduandsFileExists) {
                // Process the DOCX file to get statistics
                $result = $this->docxImportService->processDocxFile($filePath);
                
                if ($result['success']) {
                    $totalInGraduandsFile = count($result['review_data']);
                    
                    // Get ALL students for matching (not just NULL class_of_degree)
                    $allStudents = StudentNysc::select(['id', 'matric_no', 'fname', 'mname', 'lname', 'class_of_degree'])
                        ->get();
                    
                    // Create a lookup array for faster matching
                    $studentLookup = [];
                    foreach ($allStudents as $student) {
                        $studentLookup[strtoupper($student->matric_no)] = $student;
                    }
                    
                    // Match extracted data with ALL students
                    $exactMatches = [];
                    $similarMatches = [];
                    
                    foreach ($result['review_data'] as $extractedData) {
                        $graduandsMatric = strtoupper($extractedData['matric_no']);
                        $matched = false;
                        
                        // First try exact match
                        if (isset($studentLookup[$graduandsMatric])) {
                            $exactMatches[] = $extractedData;
                            $matchedRecords++;
                            $matched = true;
                        } else {
                            // Try fuzzy matching for similar matric numbers
                            $similarMatch = $this->findSimilarMatricNumber($graduandsMatric, array_keys($studentLookup));
                            
                            if ($similarMatch) {
                                $similarMatches[] = [
                                    'graduands_matric' => $extractedData['matric_no'],
                                    'db_matric' => $similarMatch,
                                    'similarity_type' => $this->getSimilarityType($graduandsMatric, $similarMatch),
                                    'class_of_degree' => $extractedData['proposed_class_of_degree'],
                                    'student_name' => $extractedData['student_name'] ?? 'Unknown'
                                ];
                                $matchedRecords++;
                                $matched = true;
                            }
                        }
                        
                        // If no match found, add to unmatched
                        if (!$matched) {
                            $unmatched_graduands[] = [
                                'matric_no' => $extractedData['matric_no'],
                                'normalized_matric' => $graduandsMatric,
                                'class_of_degree' => $extractedData['proposed_class_of_degree'],
                                'student_name' => $extractedData['student_name'] ?? 'Unknown'
                            ];
                        }
                    }
                    
                    Log::info('Matching completed', [
                        'total_graduands' => $totalInGraduandsFile,
                        'exact_matches' => count($exactMatches),
                        'similar_matches' => count($similarMatches),
                        'unmatched' => count($unmatched_graduands)
                    ]);
                }
            }
            
            $unmatched_from_graduands = count($unmatched_graduands);
            $unmatched_from_db = $studentsWithNullDegree - $matchedRecords;
            
            // Calculate match percentage
            $match_percentage = $totalInGraduandsFile > 0 ? ($matchedRecords / $totalInGraduandsFile) * 100 : 0;
            
            $analysisData = [
                // Database statistics
                'total_students_in_db' => $totalStudentsInDb,
                'students_with_null_degree' => $studentsWithNullDegree,
                'students_with_class_degree' => $studentsWithClassDegree,
                
                // GRADUANDS file statistics
                'total_in_graduands_file' => $totalInGraduandsFile,
                'graduands_file_exists' => $graduandsFileExists,
                'graduands_last_modified' => $graduandsLastModified,
                
                // Matching statistics
                'matched_records' => $matchedRecords,
                'unmatched_from_db' => $unmatched_from_db,
                'unmatched_from_graduands' => $unmatched_from_graduands,
                
                // Match rate
                'match_percentage' => $match_percentage,
                
                // Detailed data
                'null_degree_students' => $nullDegreeStudents,
                'unmatched_graduands' => $unmatched_graduands,
            ];
            
            Log::info('Data analysis completed', [
                'total_students' => $totalStudentsInDb,
                'null_degrees' => $studentsWithNullDegree,
                'graduands_records' => $totalInGraduandsFile,
                'matched' => $matchedRecords,
                'match_rate' => $match_percentage
            ]);
            
            return response()->json($analysisData);
            
        } catch (\Exception $e) {
            Log::error('Error in data analysis', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating data analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find similar matric number using fuzzy matching
     * Focuses on matching the final student number (e.g., 7194) and handles case-insensitive department matching
     * Examples: VUG/PHL/22/7194 vs VUG/phl/21/7194 (matches on final number 7194)
     *
     * @param string $target
     * @param array $candidates
     * @return string|null
     */
    private function findSimilarMatricNumber(string $target, array $candidates): ?string
    {
        // Extract the final number from target (e.g., 7194 from VUG/PHL/22/7194)
        if (!preg_match('/\/(\d+)$/', $target, $targetMatches)) {
            return null; // No final number found
        }
        $targetFinalNumber = $targetMatches[1];
        
        // Extract department from target (e.g., PHL from VUG/PHL/22/7194)
        $targetDepartment = null;
        if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $target, $deptMatches)) {
            $targetDepartment = strtoupper($deptMatches[1]);
        }
        
        foreach ($candidates as $candidate) {
            // Extract the final number from candidate
            if (!preg_match('/\/(\d+)$/', $candidate, $candidateMatches)) {
                continue; // Skip if no final number found
            }
            $candidateFinalNumber = $candidateMatches[1];
            
            // If final numbers don't match, skip
            if ($targetFinalNumber !== $candidateFinalNumber) {
                continue;
            }
            
            // Extract department from candidate
            $candidateDepartment = null;
            if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $candidate, $candDeptMatches)) {
                $candidateDepartment = strtoupper($candDeptMatches[1]);
            }
            
            // If we have departments for both, they should match (case-insensitive)
            if ($targetDepartment && $candidateDepartment) {
                if ($targetDepartment === $candidateDepartment) {
                    return $candidate; // Found match: same final number and department
                }
            } else {
                // If we can't extract departments, just match on final number
                return $candidate;
            }
        }
        
        return null;
    }

    /**
     * Determine the type of similarity between two matric numbers
     *
     * @param string $graduands
     * @param string $db
     * @return string
     */
    private function getSimilarityType(string $graduands, string $db): string
    {
        // Extract final numbers
        $graduandsFinalNumber = null;
        $dbFinalNumber = null;
        
        if (preg_match('/\/(\d+)$/', $graduands, $matches)) {
            $graduandsFinalNumber = $matches[1];
        }
        if (preg_match('/\/(\d+)$/', $db, $matches)) {
            $dbFinalNumber = $matches[1];
        }
        
        // If final numbers match, analyze other differences
        if ($graduandsFinalNumber === $dbFinalNumber) {
            $differences = [];
            
            // Check for year differences
            $graduandsYear = null;
            $dbYear = null;
            if (preg_match('/\/(\d{2})\/\d+$/', $graduands, $matches)) {
                $graduandsYear = $matches[1];
            }
            if (preg_match('/\/(\d{2})\/\d+$/', $db, $matches)) {
                $dbYear = $matches[1];
            }
            
            if ($graduandsYear !== $dbYear) {
                $differences[] = "year ($graduandsYear vs $dbYear)";
            }
            
            // Check for department differences (case-insensitive)
            $graduandsDept = null;
            $dbDept = null;
            if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $graduands, $matches)) {
                $graduandsDept = strtoupper($matches[1]);
            }
            if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $db, $matches)) {
                $dbDept = strtoupper($matches[1]);
            }
            
            if ($graduandsDept && $dbDept && $graduandsDept !== $dbDept) {
                $differences[] = "department ($graduandsDept vs $dbDept)";
            }
            
            // Check for prefix differences
            $graduandsPrefix = '';
            $dbPrefix = '';
            if (preg_match('/^(V?UG)\//', $graduands, $matches)) {
                $graduandsPrefix = $matches[1];
            }
            if (preg_match('/^(V?UG)\//', $db, $matches)) {
                $dbPrefix = $matches[1];
            }
            
            if ($graduandsPrefix !== $dbPrefix) {
                $differences[] = "prefix ($graduandsPrefix vs $dbPrefix)";
            }
            
            if (empty($differences)) {
                return 'Same student number with minor formatting differences';
            } else {
                return 'Same student number (' . $graduandsFinalNumber . ') with ' . implode(', ', $differences);
            }
        }
        
        return 'Different student numbers';
    }
}