<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use App\Models\StudentNysc;
use App\Jobs\ProcessFileImportJob;
use Illuminate\Support\Str;

class FileProcessingService
{
    protected $supportedFormats = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc' => 'application/msword',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'csv' => 'text/csv',
        'pdf' => 'application/pdf'
    ];

    protected $validClassOfDegree = [
        'First Class',
        'Second Class Upper',
        'Second Class Lower',
        'Third Class',
        'Pass'
    ];

    /**
     * Process uploaded file and extract student data
     *
     * @param string $filePath
     * @param string $originalName
     * @return array
     */
    public function processFile(string $filePath, string $originalName): array
    {
        try {
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            
            Log::info('Processing file', [
                'file_path' => $filePath,
                'original_name' => $originalName,
                'extension' => $extension
            ]);

            if (!in_array($extension, array_keys($this->supportedFormats))) {
                throw new \Exception("Unsupported file format: {$extension}");
            }

            $extractedData = [];

            switch ($extension) {
                case 'docx':
                case 'doc':
                    $extractedData = $this->processWordDocument($filePath);
                    break;
                case 'xlsx':
                case 'xls':
                    $extractedData = $this->processExcelDocument($filePath);
                    break;
                case 'csv':
                    $extractedData = $this->processCsvDocument($filePath);
                    break;
                case 'pdf':
                    $extractedData = $this->processPdfDocument($filePath);
                    break;
            }

            // Match with existing students
            $matchedData = $this->matchWithExistingStudents($extractedData);
            
            // Prepare review data
            $reviewData = $this->prepareReviewData($matchedData);

            return [
                'success' => true,
                'session_id' => Str::uuid()->toString(),
                'summary' => [
                    'total_extracted' => count($extractedData),
                    'total_matched' => count($matchedData),
                    'ready_for_review' => count($reviewData)
                ],
                'review_data' => $reviewData,
                'file_type' => $extension
            ];

        } catch (\Exception $e) {
            Log::error('File processing error', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process Word document (DOCX/DOC)
     */
    protected function processWordDocument(string $filePath): array
    {
        $extractedData = [];
        
        try {
            $phpWord = WordIOFactory::load($filePath);
            
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                        $tableData = $this->extractFromWordTable($element);
                        $extractedData = array_merge($extractedData, $tableData);
                    } else {
                        $textData = $this->extractFromWordText($element);
                        if (!empty($textData)) {
                            $extractedData = array_merge($extractedData, $textData);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Word document processing error', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $extractedData;
    }

    /**
     * Process Excel document (XLSX/XLS)
     */
    protected function processExcelDocument(string $filePath): array
    {
        $extractedData = [];
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                return $extractedData;
            }

            // Find header row and column positions
            $headerRow = $rows[0];
            $matricColumn = -1;
            $degreeColumn = -1;

            foreach ($headerRow as $index => $header) {
                $normalizedHeader = strtolower(trim($header ?? ''));
                if (strpos($normalizedHeader, 'matric') !== false) {
                    $matricColumn = $index;
                } elseif (strpos($normalizedHeader, 'class') !== false && strpos($normalizedHeader, 'degree') !== false) {
                    $degreeColumn = $index;
                }
            }

            // Process data rows
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                
                $matricNo = '';
                $classOfDegree = '';

                if ($matricColumn >= 0 && isset($row[$matricColumn])) {
                    $matricNo = trim($row[$matricColumn] ?? '');
                }

                if ($degreeColumn >= 0 && isset($row[$degreeColumn])) {
                    $classOfDegree = trim($row[$degreeColumn] ?? '');
                }

                if (!empty($matricNo) && !empty($classOfDegree)) {
                    $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                    if ($normalizedDegree) {
                        $extractedData[] = [
                            'matric_no' => strtoupper($matricNo),
                            'class_of_degree' => $normalizedDegree,
                            'source' => 'excel',
                            'row_number' => $i + 1
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Excel document processing error', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $extractedData;
    }

    /**
     * Process CSV document
     */
    protected function processCsvDocument(string $filePath): array
    {
        $extractedData = [];
        
        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \Exception('Could not open CSV file');
            }

            // Read header row
            $headers = fgetcsv($handle);
            if (!$headers) {
                fclose($handle);
                throw new \Exception('Could not read CSV headers');
            }

            // Find column positions
            $matricColumn = -1;
            $degreeColumn = -1;

            foreach ($headers as $index => $header) {
                $normalizedHeader = strtolower(trim($header));
                if (strpos($normalizedHeader, 'matric') !== false) {
                    $matricColumn = $index;
                } elseif (strpos($normalizedHeader, 'class') !== false && strpos($normalizedHeader, 'degree') !== false) {
                    $degreeColumn = $index;
                }
            }

            // Process data rows
            $rowNumber = 2;
            while (($row = fgetcsv($handle)) !== false) {
                $matricNo = '';
                $classOfDegree = '';

                if ($matricColumn >= 0 && isset($row[$matricColumn])) {
                    $matricNo = trim($row[$matricColumn]);
                }

                if ($degreeColumn >= 0 && isset($row[$degreeColumn])) {
                    $classOfDegree = trim($row[$degreeColumn]);
                }

                if (!empty($matricNo) && !empty($classOfDegree)) {
                    $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                    if ($normalizedDegree) {
                        $extractedData[] = [
                            'matric_no' => strtoupper($matricNo),
                            'class_of_degree' => $normalizedDegree,
                            'source' => 'csv',
                            'row_number' => $rowNumber
                        ];
                    }
                }
                $rowNumber++;
            }

            fclose($handle);
        } catch (\Exception $e) {
            Log::error('CSV document processing error', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $extractedData;
    }

    /**
     * Process PDF document (basic text extraction)
     */
    protected function processPdfDocument(string $filePath): array
    {
        $extractedData = [];
        
        try {
            // For PDF processing, we would need a PDF parser library
            // For now, we'll return empty array with a note
            Log::info('PDF processing not fully implemented yet', ['file_path' => $filePath]);
            
            // TODO: Implement PDF text extraction using libraries like:
            // - smalot/pdfparser
            // - tecnickcom/tcpdf
            // - setasign/fpdf
            
        } catch (\Exception $e) {
            Log::error('PDF document processing error', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        return $extractedData;
    }

    /**
     * Extract data from Word table
     */
    protected function extractFromWordTable($table): array
    {
        $data = [];
        $headers = [];
        $matricColumn = -1;
        $degreeColumn = -1;
        
        $rows = $table->getRows();
        
        if (count($rows) > 0) {
            $headerRow = $rows[0];
            $cells = $headerRow->getCells();
            
            foreach ($cells as $index => $cell) {
                $cellText = $this->getCellText($cell);
                $normalizedText = strtolower(trim($cellText));
                
                if (strpos($normalizedText, 'matric') !== false) {
                    $matricColumn = $index;
                } elseif (strpos($normalizedText, 'class') !== false && strpos($normalizedText, 'degree') !== false) {
                    $degreeColumn = $index;
                }
                
                $headers[] = $cellText;
            }
        }
        
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $cells = $row->getCells();
            
            $matricNo = '';
            $classOfDegree = '';
            
            if ($matricColumn >= 0 && isset($cells[$matricColumn])) {
                $matricNo = trim($this->getCellText($cells[$matricColumn]));
            }
            
            if ($degreeColumn >= 0 && isset($cells[$degreeColumn])) {
                $classOfDegree = trim($this->getCellText($cells[$degreeColumn]));
            }
            
            if (!empty($matricNo) && !empty($classOfDegree)) {
                $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                if ($normalizedDegree) {
                    $data[] = [
                        'matric_no' => strtoupper($matricNo),
                        'class_of_degree' => $normalizedDegree,
                        'source' => 'word_table',
                        'row_number' => $i + 1
                    ];
                }
            }
        }
        
        return $data;
    }

    /**
     * Extract data from Word text elements
     */
    protected function extractFromWordText($element): array
    {
        $data = [];
        $text = '';
        
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
        } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
            $text = $element->getText();
        }
        
        if (empty($text)) {
            return $data;
        }
        
        // Pattern matching for matric number and class of degree
        $patterns = [
            '/([A-Z]{2,4}\/\d{4}\/\d{3,4})\s*[-:]\s*([^,\n\r]+)/i',
            '/([A-Z]{2,4}\/\d{4}\/\d{3,4})\s+([^,\n\r]+)/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $matricNo = trim($match[1]);
                    $classOfDegree = trim($match[2]);
                    
                    $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                    if ($normalizedDegree) {
                        $data[] = [
                            'matric_no' => strtoupper($matricNo),
                            'class_of_degree' => $normalizedDegree,
                            'source' => 'word_text',
                            'row_number' => null
                        ];
                    }
                }
            }
        }
        
        return $data;
    }

    /**
     * Get text content from a Word table cell
     */
    protected function getCellText($cell): string
    {
        $text = '';
        
        foreach ($cell->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $text .= $element->getText() . ' ';
            } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                foreach ($element->getElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $text .= $textElement->getText() . ' ';
                    }
                }
            }
        }
        
        return trim($text);
    }

    /**
     * Normalize class of degree to standard format
     */
    protected function normalizeClassOfDegree(string $degree): ?string
    {
        $degree = trim($degree);
        $lowerDegree = strtolower($degree);
        
        $mappings = [
            'first class' => 'First Class',
            '1st class' => 'First Class',
            'first class honour' => 'First Class',
            'first class honors' => 'First Class',
            
            'second class upper' => 'Second Class Upper',
            '2nd class upper' => 'Second Class Upper',
            'second class honour upper' => 'Second Class Upper',
            'second class honors upper' => 'Second Class Upper',
            '2:1' => 'Second Class Upper',
            
            'second class lower' => 'Second Class Lower',
            '2nd class lower' => 'Second Class Lower',
            'second class honour lower' => 'Second Class Lower',
            'second class honors lower' => 'Second Class Lower',
            '2:2' => 'Second Class Lower',
            
            'third class' => 'Third Class',
            '3rd class' => 'Third Class',
            'third class honour' => 'Third Class',
            'third class honors' => 'Third Class',
            
            'pass' => 'Pass',
            'ordinary pass' => 'Pass'
        ];
        
        foreach ($mappings as $variation => $standard) {
            if (strpos($lowerDegree, $variation) !== false) {
                return $standard;
            }
        }
        
        return null;
    }

    /**
     * Match extracted data with existing students
     */
    protected function matchWithExistingStudents(array $extractedData): array
    {
        $matchedData = [];
        
        foreach ($extractedData as $data) {
            $matricNo = $data['matric_no'];
            
            // Find student with matching matric number (case-insensitive)
            $student = StudentNysc::whereRaw('UPPER(matric_no) = ?', [strtoupper($matricNo)])->first();
            
            if ($student) {
                // Check if student already has class_of_degree to prevent duplicates
                $matchedData[] = [
                    'student_id' => $student->id,
                    'matric_no' => $matricNo,
                    'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'current_class_of_degree' => $student->class_of_degree,
                    'proposed_class_of_degree' => $data['class_of_degree'],
                    'match_confidence' => 'exact',
                    'source' => $data['source'],
                    'row_number' => $data['row_number'],
                    'already_has_degree' => !is_null($student->class_of_degree)
                ];
                
                Log::info('Student matched', [
                    'matric_no' => $matricNo,
                    'student_id' => $student->id,
                    'current_degree' => $student->class_of_degree,
                    'proposed_degree' => $data['class_of_degree']
                ]);
            } else {
                Log::warning('No student found for matric number', ['matric_no' => $matricNo]);
            }
        }
        
        return $matchedData;
    }

    /**
     * Prepare matched data for admin review
     */
    protected function prepareReviewData(array $matchedData): array
    {
        $reviewData = [];
        
        foreach ($matchedData as $data) {
            $reviewData[] = [
                'student_id' => $data['student_id'],
                'matric_no' => $data['matric_no'],
                'student_name' => $data['student_name'],
                'current_class_of_degree' => $data['current_class_of_degree'],
                'proposed_class_of_degree' => $data['proposed_class_of_degree'],
                'match_confidence' => $data['match_confidence'],
                'needs_update' => $data['current_class_of_degree'] !== $data['proposed_class_of_degree'],
                'approved' => false,
                'source' => $data['source'],
                'row_number' => $data['row_number'],
                'already_has_degree' => $data['already_has_degree'],
                'is_duplicate' => $data['already_has_degree'] && $data['current_class_of_degree'] === $data['proposed_class_of_degree']
            ];
        }
        
        return $reviewData;
    }

    /**
     * Apply approved updates to the database
     */
    public function applyApprovedUpdates(array $approvedData): array
    {
        $updateCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $errors = [];
        
        try {
            \DB::beginTransaction();
            
            foreach ($approvedData as $data) {
                if (!isset($data['approved']) || !$data['approved']) {
                    continue;
                }
                
                try {
                    $student = StudentNysc::find($data['student_id']);
                    if ($student) {
                        // Check if student already has this class of degree (prevent duplicates)
                        if ($student->class_of_degree === $data['proposed_class_of_degree']) {
                            $skippedCount++;
                            Log::info('Skipped duplicate update', [
                                'student_id' => $student->id,
                                'matric_no' => $student->matric_no,
                                'existing_degree' => $student->class_of_degree
                            ]);
                            continue;
                        }
                        
                        $oldValue = $student->class_of_degree;
                        $student->class_of_degree = $data['proposed_class_of_degree'];
                        $student->save();
                        
                        $updateCount++;
                        
                        Log::info('Student class of degree updated', [
                            'student_id' => $student->id,
                            'matric_no' => $student->matric_no,
                            'old_value' => $oldValue,
                            'new_value' => $data['proposed_class_of_degree']
                        ]);
                    } else {
                        $errorCount++;
                        $errors[] = "Student not found: {$data['matric_no']}";
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Error updating {$data['matric_no']}: " . $e->getMessage();
                    Log::error('Error updating student', [
                        'matric_no' => $data['matric_no'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            \DB::commit();
            
            Log::info('Batch update completed', [
                'updated_count' => $updateCount,
                'error_count' => $errorCount,
                'skipped_count' => $skippedCount
            ]);
            
        } catch (\Exception $e) {
            \DB::rollback();
            Log::error('Transaction failed during batch update', ['error' => $e->getMessage()]);
            throw $e;
        }
        
        return [
            'success' => true,
            'updated_count' => $updateCount,
            'error_count' => $errorCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors
        ];
    }

    /**
     * Get supported file formats
     */
    public function getSupportedFormats(): array
    {
        return $this->supportedFormats;
    }

    /**
     * Validate file format
     */
    public function isValidFormat(string $extension): bool
    {
        return in_array(strtolower($extension), array_keys($this->supportedFormats));
    }
}