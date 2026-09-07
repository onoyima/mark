<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\DocxImportService;
use App\Models\AdminSetting;
use App\Models\StudentNerd;
use App\Models\NyscSession;

/**
 * Nerd review: reconcile nerd graduate fields on student_nerds records
 * (final CGPA, class of degree, graduation date and graduation session)
 * against an uploaded file, and apply approved updates.
 *
 * The student_nerds table is intentionally separate from student_nysc:
 * nerd review writes ONLY here. The graduation session and graduation date
 * are captured at upload time (file-level properties), not per row.
 */
class NyscNerdReviewController extends Controller
{
    protected $docxImportService;

    public function __construct(DocxImportService $docxImportService)
    {
        $this->docxImportService = $docxImportService;
    }

    /**
     * Parse the uploaded nerd file and match its rows against student_nerds.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getNerdMatches(Request $request): JsonResponse
    {
        try {
            $storageDir = storage_path('app');
            $requestedFile = $request->query('file');
            $availableFiles = $this->listNerdFiles();
            $currentFileName = $requestedFile ?: ($availableFiles[0]['name'] ?? null);

            if (!$currentFileName) {
                return $this->emptyResponse('No nerd *.csv or *.docx file found in storage/app', null, $availableFiles);
            }

            $currentFilePath = storage_path('app/' . $currentFileName);
            if (!file_exists($currentFilePath)) {
                return $this->emptyResponse('Nerd file not found: ' . $currentFileName, $currentFileName, $availableFiles);
            }

            // Extract data from the selected nerd file. CSV files carry the
            // full nerd fields; DOCX files fall back to the existing pipeline
            // (matric + class of degree only).
            $isCsv = strtolower(pathinfo($currentFileName, PATHINFO_EXTENSION)) === 'csv';

            if ($isCsv) {
                try {
                    $reviewData = $this->docxImportService->extractNerdDataFromCsvFile($currentFilePath);
                } catch (\Throwable $e) {
                    Log::error('Error extracting CSV nerd data', ['error' => $e->getMessage()]);
                    return $this->emptyResponse('Failed to process nerd CSV file: ' . $e->getMessage(), $currentFileName, $availableFiles);
                }
            } else {
                if (!class_exists('PhpOffice\\PhpWord\\IOFactory')) {
                    return $this->emptyResponse('PhpWord library not available on server', $currentFileName, $availableFiles);
                }
                $result = $this->docxImportService->processDocxFile($currentFilePath);
                if (!$result['success']) {
                    return $this->emptyResponse('Failed to process nerd DOCX file: ' . ($result['error'] ?? 'unknown error'), $currentFileName, $availableFiles);
                }
                // Map DOCX extraction onto the nerd field shape.
                $reviewData = array_map(function ($rec) {
                    return [
                        'matric_no' => $rec['matric_no'],
                        'final_cgpa' => null,
                        'class_of_degree' => $rec['proposed_class_of_degree'] ?? $rec['class_of_degree'] ?? null,
                        'graduation_date' => null,
                        'graduation_session' => null,
                        'student_name' => $rec['student_name'] ?? null,
                        'source' => $rec['source'] ?? 'docx',
                        'row_number' => $rec['row_number'] ?? null,
                    ];
                }, $result['review_data']);
            }

            // Students currently in the student_nerds table for this session.
            $sessionId = $this->fileSessionId($currentFileName) ?: AdminSetting::get('active_session_id');
            $query = StudentNerd::select([
                'id', 'student_id', 'matric_no', 'fname', 'mname', 'lname',
                'cgpa', 'class_of_degree', 'graduation_year', 'graduation_date',
                'course_study'
            ]);
            if ($sessionId) {
                $query->where('nysc_session_id', $sessionId);
            }
            $allStudents = $query->get();

            $studentLookup = [];
            foreach ($allStudents as $student) {
                $studentLookup[strtoupper((string) $student->matric_no)] = $student;
            }

            // Graduation session and date are file-level, captured at upload.
            $fileMeta = $this->nerdMetaFor($currentFileName);
            $fileGraduationSession = $fileMeta['graduation_session'] ?? null;
            $fileGraduationDate = $fileMeta['graduation_date'] ?? null;

            $exactMatches = [];
            $similarMatches = [];
            $unmatched = [];

            foreach ($reviewData as $extractedData) {
                $nerdMatric = strtoupper((string) $extractedData['matric_no']);
                $student = $studentLookup[$nerdMatric] ?? null;
                $matchType = 'exact';
                $similarityType = null;

                if (!$student) {
                    $similarMatric = $this->findSimilarMatricNumber($nerdMatric, array_keys($studentLookup));
                    if ($similarMatric) {
                        $student = $studentLookup[$similarMatric];
                        $matchType = 'similar';
                        $similarityType = $this->getSimilarityType($nerdMatric, $similarMatric);
                    }
                }

                if (!$student) {
                    $unmatched[] = [
                        'docx_matric' => $extractedData['matric_no'],
                        'normalized_matric' => $nerdMatric,
                        'class_of_degree' => $this->normalizeClassOfDegree($extractedData['class_of_degree']),
                        'final_cgpa' => $extractedData['final_cgpa'],
                        'student_name' => $extractedData['student_name'] ?? 'Unknown'
                    ];
                    continue;
                }

                // Proposed values: CGPA and class from the file row; graduation
                // session and date from the upload-level file metadata.
                $proposedCgpa = $extractedData['final_cgpa'] ?? null;
                $proposedDegree = $this->normalizeClassOfDegree($extractedData['class_of_degree']);
                $proposedGraduationSession = $fileGraduationSession;
                $proposedGraduationDate = $fileGraduationDate;

                $needsUpdate = $this->needsUpdate($student, $proposedCgpa, $proposedDegree, $proposedGraduationSession, $proposedGraduationDate);

                $match = [
                    'student_id' => $student->id,
                    'nerd_student_id' => $student->id,
                    'matric_no' => $student->matric_no,
                    'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'current_programme' => $student->course_study,
                    'proposed_programme' => $extractedData['programme'] ?? null,
                    'current_cgpa' => $student->cgpa,
                    'proposed_cgpa' => $proposedCgpa,
                    'current_class_of_degree' => $student->class_of_degree,
                    'proposed_class_of_degree' => $proposedDegree,
                    'current_graduation_session' => $student->graduation_year,
                    'proposed_graduation_session' => $proposedGraduationSession,
                    'current_graduation_date' => $student->graduation_date,
                    'proposed_graduation_date' => $proposedGraduationDate,
                    'needs_update' => $needsUpdate,
                    'approved' => false,
                    'source' => $extractedData['source'] ?? 'csv',
                    'row_number' => $extractedData['row_number'] ?? null,
                    'match_type' => $matchType,
                ];

                if ($matchType === 'similar') {
                    $match['graduands_matric'] = $extractedData['matric_no'];
                    $match['similarity_type'] = $similarityType;
                    $similarMatches[] = $match;
                } else {
                    $exactMatches[] = $match;
                }
            }

            $allMatches = array_merge($exactMatches, $similarMatches);

            $summary = [
                'total_students' => $allStudents->count(),
                'total_extracted_from_file' => count($reviewData),
                'total_matches_found' => count($allMatches),
                'exact_matches' => count($exactMatches),
                'similar_matches' => count($similarMatches),
                'total_unmatched' => count($unmatched),
                'current_file' => $currentFileName,
                'available_files' => $availableFiles,
                'file_last_modified' => date('Y-m-d H:i:s', filemtime($currentFilePath))
            ];

            Log::info('Nerd review matching completed', [
                'total_nerd' => count($reviewData),
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
                    ? "Found " . count($allMatches) . " matches (" . count($exactMatches) . " exact, " . count($similarMatches) . " similar) from " . $currentFileName
                    : "No matches found in " . $currentFileName
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in nerd matching', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->emptyResponse('Error processing nerd data: ' . $e->getMessage());
        }
    }

    /**
     * Upload a nerd file tied to a specific NYSC session.
     *
     * The graduation session and graduation date are required at upload time
     * and stored in a sidecar JSON (storage/app/nerd_meta.json). They are
     * applied to every matched record.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadNerdFile(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'file' => [
                    'required',
                    'file',
                    'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/octet-stream,text/csv,text/plain,application/csv,application/vnd.ms-excel',
                    'max:10240'
                ],
                'session_id' => 'required|integer|exists:nysc_sessions,id',
                'graduation_session' => 'required|string|max:100',
                'graduation_date' => 'required|date'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'File validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $file = $request->file('file');
            $fileName = basename($file->getClientOriginalName());

            if (!preg_match('/\.(docx|csv)$/i', $fileName)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nerd files must be .docx or .csv files'
                ], 422);
            }

            $graduationDate = $request->input('graduation_date');
            $graduationDateDisplay = null;
            if ($graduationDate) {
                $parsed = \DateTime::createFromFormat('Y-m-d', $graduationDate);
                if (!$parsed) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid graduation date format'
                    ], 422);
                }
                $graduationDateDisplay = $parsed->format('d/m/Y');
            }

            $targetPath = storage_path('app/' . $fileName);
            $file->move(storage_path('app'), $fileName);

            $meta = $this->readNerdMeta();
            $meta[$fileName] = [
                'session_id' => (int) $request->input('session_id'),
                'graduation_session' => trim((string) $request->input('graduation_session')),
                'graduation_date' => $graduationDateDisplay,
                'uploaded_at' => now()->toDateTimeString()
            ];
            $this->writeNerdMeta($meta);

            return response()->json([
                'success' => true,
                'message' => 'Nerd file uploaded successfully',
                'file' => [
                    'name' => $fileName,
                    'session_id' => (int) $request->input('session_id'),
                    'session_name' => NyscSession::find((int) $request->input('session_id'))?->name,
                    'graduation_session' => trim((string) $request->input('graduation_session')),
                    'graduation_date' => $graduationDateDisplay,
                    'size' => $this->formatBytes(filesize($targetPath)),
                    'modified' => date('Y-m-d H:i:s', filemtime($targetPath))
                ],
                'files' => $this->listNerdFiles()
            ]);
        } catch (\Throwable $e) {
            Log::error('Error uploading nerd file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error uploading nerd file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List uploaded nerd files with their session mapping.
     *
     * @return JsonResponse
     */
    public function getNerdFiles(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'files' => $this->listNerdFiles()
            ]);
        } catch (\Throwable $e) {
            Log::error('Error listing nerd files', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error listing nerd files: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an uploaded nerd file and its sidecar metadata.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteNerdFile(Request $request): JsonResponse
    {
        try {
            $fileName = trim((string) $request->query('file', $request->input('file', '')));
            if ($fileName === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No file name provided'
                ], 422);
            }

            if (!preg_match('/\.(docx|csv)$/i', $fileName) || strpbrk($fileName, "/\\") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file name'
                ], 422);
            }

            $targetPath = storage_path('app/' . $fileName);
            if (file_exists($targetPath)) {
                if (!@unlink($targetPath)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to delete file from storage'
                    ], 500);
                }
            }

            $meta = $this->readNerdMeta();
            if (isset($meta[$fileName])) {
                unset($meta[$fileName]);
                $this->writeNerdMeta($meta);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nerd file deleted successfully',
                'files' => $this->listNerdFiles()
            ]);
        } catch (\Throwable $e) {
            Log::error('Error deleting nerd file', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error deleting nerd file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply approved nerd field updates (cgpa, class_of_degree, graduation
     * date and graduation session) to student_nerds records.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function applyNerdUpdates(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'file' => 'nullable|string',
                'updates' => 'required|array',
                'updates.*.student_id' => 'required|integer',
                'updates.*.nerd_student_id' => 'sometimes|integer',
                'updates.*.matric_no' => 'required|string',
                'updates.*.proposed_cgpa' => 'nullable|numeric',
                'updates.*.proposed_class_of_degree' => 'nullable|string',
                'updates.*.proposed_graduation_date' => 'nullable|string|max:100',
                'updates.*.proposed_graduation_session' => 'nullable|string|max:100',
                'updates.*.proposed_programme' => 'nullable|string|max:255',
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
            $fileMeta = $this->nerdMetaFor((string) $request->input('file', ''));
            $fileGraduationSession = $fileMeta['graduation_session'] ?? null;
            $fileGraduationDate = $fileMeta['graduation_date'] ?? null;
            $updatedCount = 0;
            $skippedCount = 0;
            $notApprovedCount = 0;
            $errorCount = 0;
            $errors = [];

            try {
                \DB::beginTransaction();

                foreach ($updates as $update) {
                    if (!($update['approved'] ?? false)) {
                        $notApprovedCount++;
                        continue;
                    }

                    try {
                        $student = StudentNerd::find($update['nerd_student_id'] ?? $update['student_id']);
                        if (!$student) {
                            $errorCount++;
                            $errors[] = "Nerd record not found: {$update['matric_no']}";
                            continue;
                        }

                        $proposedCgpa = isset($update['proposed_cgpa']) && $update['proposed_cgpa'] !== '' && $update['proposed_cgpa'] !== null
                            ? round((float) $update['proposed_cgpa'], 2)
                            : null;
                        $proposedDegree = $this->normalizeClassOfDegree($update['proposed_class_of_degree'] ?? null);
                        $proposedGraduationDate = (($update['proposed_graduation_date'] ?? null) !== '' && isset($update['proposed_graduation_date']))
                            ? $update['proposed_graduation_date']
                            : $fileGraduationDate;
                        $proposedSession = (($update['proposed_graduation_session'] ?? null) !== '' && isset($update['proposed_graduation_session']))
                            ? $update['proposed_graduation_session']
                            : $fileGraduationSession;

                        $currentCgpa = $student->cgpa !== null ? round((float) $student->cgpa, 2) : null;
                        $currentDegree = ($student->class_of_degree ?? '') !== '' ? $student->class_of_degree : null;
                        $currentGraduationDate = ($student->graduation_date ?? '') !== '' ? $student->graduation_date : null;
                        $currentSession = ($student->graduation_year ?? '') !== '' ? $student->graduation_year : null;
                        $currentProgramme = ($student->course_study ?? '') !== '' ? trim((string) $student->course_study) : null;
                        $proposedProgramme = isset($update['proposed_programme']) && trim((string) $update['proposed_programme']) !== ''
                            ? trim((string) $update['proposed_programme'])
                            : null;

                        $changed = false;

                        if ($proposedProgramme !== null && strtoupper($proposedProgramme) !== strtoupper((string) $currentProgramme)) {
                            $student->course_study = $proposedProgramme;
                            $changed = true;
                        }

                        if ($proposedCgpa !== $currentCgpa) {
                            $student->cgpa = $proposedCgpa;
                            $changed = true;
                        }
                        if ($proposedDegree !== $currentDegree) {
                            $student->class_of_degree = $proposedDegree;
                            $changed = true;
                        }
                        if ($proposedGraduationDate !== $currentGraduationDate) {
                            $student->graduation_date = $proposedGraduationDate;
                            $changed = true;
                        }
                        if ($proposedSession !== $currentSession) {
                            $student->graduation_year = $proposedSession;
                            $changed = true;
                        }

                        if (!$changed) {
                            $skippedCount++;
                            continue;
                        }

                        $student->save();
                        $updatedCount++;
                    } catch (\Exception $e) {
                        $errorCount++;
                        $errors[] = "Error updating {$update['matric_no']}: " . $e->getMessage();
                    }
                }

                \DB::commit();

                $message = "Nerd updates applied! {$updatedCount} records updated";
                if ($skippedCount > 0) {
                    $message .= ", {$skippedCount} records skipped (no change)";
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
                Log::error('Transaction failed during nerd update', ['error' => $e->getMessage()]);
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error applying nerd updates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error applying nerd updates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine whether any nerd field differs between the file row and the DB.
     *
     * @param StudentNerd $student
     * @param float|null $proposedCgpa
     * @param string|null $proposedDegree
     * @param string|null $proposedGraduationSession
     * @param string|null $proposedGraduationDate
     * @return bool
     */
    private function needsUpdate(StudentNerd $student, ?float $proposedCgpa, ?string $proposedDegree, ?string $proposedGraduationSession, ?string $proposedGraduationDate): bool
    {
        $currentCgpa = $student->cgpa !== null ? round((float) $student->cgpa, 2) : null;
        $currentDegree = ($student->class_of_degree ?? '') !== '' ? $student->class_of_degree : null;
        $currentGraduationDate = ($student->graduation_date ?? '') !== '' ? $student->graduation_date : null;
        $currentSession = ($student->graduation_year ?? '') !== '' ? $student->graduation_year : null;

        if ($proposedCgpa !== $currentCgpa) {
            return true;
        }
        if ($proposedDegree !== $currentDegree) {
            return true;
        }
        if ($proposedGraduationDate !== $currentGraduationDate) {
            return true;
        }
        if ($proposedGraduationSession !== $currentSession) {
            return true;
        }
        return false;
    }

    /**
     * Normalize a class of degree value to one of the four canonical nerd
     * labels: First Class Honours, Second Class Upper Division,
     * Second Class Lower Division, Third Class.
     *
     * @param mixed $value
     * @return string|null
     */
    private function normalizeClassOfDegree($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $lower = strtolower($value);

        if (preg_match('/1st class|first class/', $lower)) {
            return 'First Class Honours';
        }

        if (preg_match('/2(nd|:1|\.1|-1)|second class.*upper|upper.*second class/', $lower)) {
            return 'Second Class Upper Division';
        }

        if (preg_match('/2(:2|\.2|-2)|second class.*lower|lower.*second class/', $lower)) {
            return 'Second Class Lower Division';
        }

        if (preg_match('/3rd class|third class/', $lower)) {
            return 'Third Class';
        }

        if (strpos($lower, 'second class') !== false) {
            return 'Second Class Upper Division';
        }

        return null;
    }

    /**
     * Path of the sidecar JSON that maps nerd files to sessions.
     */
    private function nerdMetaPath(): string
    {
        return storage_path('app/nerd_meta.json');
    }

    /**
     * Read the sidecar JSON as an array.
     */
    private function readNerdMeta(): array
    {
        $path = $this->nerdMetaPath();
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write the sidecar JSON atomically.
     */
    private function writeNerdMeta(array $meta): void
    {
        $path = $this->nerdMetaPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $contents = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $contents) !== false && @rename($tmp, $path)) {
            return;
        }
        @unlink($tmp);
        file_put_contents($path, $contents);
    }

    /**
     * Get the meta entry for a single nerd file (with session name resolved).
     */
    private function nerdMetaFor(string $fileName): array
    {
        $meta = $this->readNerdMeta();
        $entry = $meta[$fileName] ?? [];
        $entry['session_name'] = null;
        if (isset($entry['session_id'])) {
            $session = NyscSession::find($entry['session_id']);
            $entry['session_name'] = $session ? $session->name : null;
        }
        return $entry;
    }

    /**
     * Session id a nerd file is tied to, or null when not assigned.
     */
    private function fileSessionId(?string $fileName): ?int
    {
        if (!$fileName) {
            return null;
        }
        $entry = $this->nerdMetaFor($fileName);
        return isset($entry['session_id']) ? (int) $entry['session_id'] : null;
    }

    /**
     * List all nerd *.csv / *.docx files in storage/app with their session meta.
     */
    private function listNerdFiles(): array
    {
        $files = [];
        $paths = array_merge(
            glob(storage_path('app/*.csv')) ?: [],
            glob(storage_path('app/*.docx')) ?: []
        );
        foreach ($paths as $f) {
            $fileName = basename($f);
            $meta = $this->nerdMetaFor($fileName);
            $files[] = [
                'name' => $fileName,
                'size' => $this->formatBytes(filesize($f)),
                'modified' => date('Y-m-d H:i:s', filemtime($f)),
                'session_id' => $meta['session_id'] ?? null,
                'session_name' => $meta['session_name'] ?? null,
                'graduation_session' => $meta['graduation_session'] ?? null,
                'graduation_date' => $meta['graduation_date'] ?? null
            ];
        }
        usort($files, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $files;
    }

    /**
     * Format bytes to a human readable string.
     */
    private function formatBytes($size, $precision = 2)
    {
        if ($size <= 0) {
            return '0 B';
        }
        $base = log($size, 1024);
        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
    }

    /**
     * Find a candidate matric that shares the same trailing number.
     */
    private function findSimilarMatricNumber(string $target, array $candidates): ?string
    {
        if (!preg_match('/(\d+)$/', $target, $targetMatches)) {
            return null;
        }
        $targetFinalNumber = $targetMatches[1];

        foreach ($candidates as $candidate) {
            if (!preg_match('/(\d+)$/', $candidate, $candidateMatches)) {
                continue;
            }
            if ($targetFinalNumber === $candidateMatches[1]) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Describe the similarity between a file matric and a DB matric.
     */
    private function getSimilarityType(string $fileMatric, string $db): string
    {
        $fileFinalNumber = null;
        $dbFinalNumber = null;
        if (preg_match('/\/(\d+)$/', $fileMatric, $matches)) {
            $fileFinalNumber = $matches[1];
        }
        if (preg_match('/\/(\d+)$/', $db, $matches)) {
            $dbFinalNumber = $matches[1];
        }

        if ($fileFinalNumber === $dbFinalNumber) {
            $differences = [];

            $fileYear = null;
            $dbYear = null;
            if (preg_match('/\/(\d{2})\/\d+$/', $fileMatric, $matches)) {
                $fileYear = $matches[1];
            }
            if (preg_match('/\/(\d{2})\/\d+$/', $db, $matches)) {
                $dbYear = $matches[1];
            }
            if ($fileYear !== $dbYear) {
                $differences[] = "year ($fileYear vs $dbYear)";
            }

            $fileDept = null;
            $dbDept = null;
            if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $fileMatric, $matches)) {
                $fileDept = strtoupper($matches[1]);
            }
            if (preg_match('/\/([A-Za-z]+)\/\d+\/\d+$/', $db, $matches)) {
                $dbDept = strtoupper($matches[1]);
            }
            if ($fileDept && $dbDept && $fileDept !== $dbDept) {
                $differences[] = "department ($fileDept vs $dbDept)";
            }

            return empty($differences)
                ? 'Same student number with minor formatting differences'
                : 'Same student number (' . $fileFinalNumber . ') with ' . implode(', ', $differences);
        }

        return 'Different student numbers';
    }

    /**
     * Build a consistent empty/failure response payload.
     */
    private function emptyResponse(string $message, ?string $currentFile = null, array $availableFiles = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'summary' => [
                'total_students' => 0,
                'total_extracted_from_file' => 0,
                'total_matches_found' => 0,
                'exact_matches' => 0,
                'similar_matches' => 0,
                'total_unmatched' => 0,
                'current_file' => $currentFile,
                'available_files' => $availableFiles,
                'file_last_modified' => null
            ],
            'matches' => [],
            'unmatched' => [],
            'message' => $message
        ]);
    }
}