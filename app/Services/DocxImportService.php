<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use App\Models\StudentNysc;
use Illuminate\Support\Str;

class DocxImportService
{
    protected $tempStoragePath;
    protected $validClassOfDegree = [
        'First Class',
        'Second Class Upper',
        'Second Class Lower',
        'Third Class',
        'Pass'
    ];

    public function __construct()
    {
        $this->tempStoragePath = storage_path('app/temp/docx_imports');
        
        // Ensure temp directory exists
        if (!file_exists($this->tempStoragePath)) {
            mkdir($this->tempStoragePath, 0755, true);
        }
    }

    /**
     * Process uploaded DOCX file and extract matriculation numbers with class of degree
     *
     * @param string $filePath
     * @return array
     */
    public function processDocxFile(string $filePath): array
    {
        try {
            Log::info('Starting DOCX file processing', ['file_path' => $filePath]);
            
            // Extract data from DOCX
            $extractedData = $this->extractMatricAndDegreeData($filePath);
            
            // Match with existing students
            $matchedData = $this->matchWithExistingStudents($extractedData);
            
            // Prepare data for review
            $reviewData = $this->prepareReviewData($matchedData);
            
            Log::info('DOCX processing completed', [
                'extracted_count' => count($extractedData),
                'matched_count' => count($matchedData),
                'review_ready_count' => count($reviewData)
            ]);
            
            // Count matched vs unmatched
            $matchedCount = count(array_filter($matchedData, function($item) {
                return $item['is_matched'] ?? false;
            }));
            $unmatchedCount = count($matchedData) - $matchedCount;
            
            return [
                'success' => true,
                'session_id' => Str::uuid()->toString(),
                'summary' => [
                    'total_extracted' => count($extractedData),
                    'total_matched' => $matchedCount,
                    'total_unmatched' => $unmatchedCount,
                    'ready_for_review' => count($reviewData)
                ],
                'review_data' => $reviewData
            ];
            
        } catch (\Throwable $e) {
            Log::error('Error processing DOCX file', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to process DOCX file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract matriculation numbers and class of degree from DOCX file
     * Enhanced to scan all content thoroughly
     *
     * @param string $filePath
     * @return array
     */
    public function extractMatricAndDegreeData(string $filePath): array
    {
        $extractedData = [];
        
        try {
            Log::info('Starting comprehensive DOCX file extraction', ['file_path' => $filePath, 'file_exists' => file_exists($filePath)]);
            
            // Check if file exists and is readable
            if (!file_exists($filePath)) {
                throw new \Exception("File does not exist: {$filePath}");
            }
            
            if (!is_readable($filePath)) {
                throw new \Exception("File is not readable: {$filePath}");
            }
            
            // Load the DOCX file
            $phpWord = IOFactory::load($filePath);
            
            // Process each section in the document - COMPREHENSIVE SCAN
            $sectionCount = 0;
            foreach ($phpWord->getSections() as $section) {
                $sectionCount++;
                Log::info("Processing section {$sectionCount}");
                
                $elementCount = 0;
                // Process ALL elements in the section
                foreach ($section->getElements() as $element) {
                    $elementCount++;
                    
                    if ($element instanceof Table) {
                        Log::info("Processing table {$elementCount} in section {$sectionCount}");
                        $tableData = $this->extractFromTable($element);
                        $extractedData = array_merge($extractedData, $tableData);
                        Log::info("Extracted " . count($tableData) . " records from table {$elementCount}");
                    } else {
                        // Process paragraphs and text runs - MORE THOROUGH
                        $textData = $this->extractFromText($element);
                        if (!empty($textData)) {
                            $extractedData = array_merge($extractedData, $textData);
                            Log::info("Extracted " . count($textData) . " records from text element {$elementCount}");
                        }
                    }
                }
                
                Log::info("Section {$sectionCount} processed: {$elementCount} elements");
            }
            
            // Additional comprehensive text extraction - scan entire document as plain text
            $this->performFullTextScan($phpWord, $extractedData);
            
            // Remove duplicates based on matric_no
            $extractedData = $this->removeDuplicateRecords($extractedData);
            
            Log::info('Comprehensive data extraction completed', [
                'total_sections' => $sectionCount,
                'extracted_count' => count($extractedData),
                'unique_matric_numbers' => count(array_unique(array_column($extractedData, 'matric_no')))
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error extracting data from DOCX', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
        
        return $extractedData;
    }

    /**
     * Extract data from table elements
     *
     * @param Table $table
     * @return array
     */
    protected function extractFromTable(Table $table): array
    {
        $data = [];
        $headers = [];
        $matricColumn = -1;
        $degreeColumn = -1;
        
        $rows = $table->getRows();
        
        // Process header row to find column positions
        if (count($rows) > 0) {
            $headerRow = $rows[0];
            $cells = $headerRow->getCells();
            
            foreach ($cells as $index => $cell) {
                $cellText = $this->getCellText($cell);
                $normalizedText = strtolower(trim($cellText));
                
                // More flexible matching for matric number column
                if (strpos($normalizedText, 'matric') !== false || 
                    strpos($normalizedText, 'reg') !== false ||
                    strpos($normalizedText, 'student') !== false && strpos($normalizedText, 'no') !== false) {
                    $matricColumn = $index;
                    Log::info("Found matric column at index {$index}: {$cellText}");
                }
                
                // More flexible matching for class of degree column
                if ((strpos($normalizedText, 'class') !== false && strpos($normalizedText, 'degree') !== false) ||
                    strpos($normalizedText, 'grade') !== false ||
                    strpos($normalizedText, 'cgpa') !== false ||
                    strpos($normalizedText, 'result') !== false) {
                    $degreeColumn = $index;
                    Log::info("Found degree column at index {$index}: {$cellText}");
                }
                
                $headers[] = $cellText;
            }
            
            Log::info('Table headers found', [
                'headers' => $headers,
                'matric_column' => $matricColumn,
                'degree_column' => $degreeColumn
            ]);
        }
        
        // Process data rows
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
                        'source' => 'table',
                        'row_number' => $i + 1
                    ];
                }
            }
        }
        
        return $data;
    }

    /**
     * Extract data from text elements (paragraphs)
     *
     * @param mixed $element
     * @return array
     */
    protected function extractFromText($element): array
    {
        $data = [];
        $text = '';
        
        // Extract text content from various element types
        if (method_exists($element, 'getText')) {
            $maybeText = $element->getText();
            $text = is_string($maybeText) ? $maybeText : '';
        } elseif ($element instanceof TextRun) {
            // TextRun doesn't reliably provide a flat text string; concatenate child texts
            $buffer = '';
            foreach ($element->getElements() as $textElement) {
                if (method_exists($textElement, 'getText')) {
                    $t = $textElement->getText();
                    if (is_string($t)) { $buffer .= $t . ' '; }
                }
            }
            $text = trim($buffer);
        }
        
        if (empty($text)) {
            return $data;
        }
        
        // Pattern matching for matric number and class of degree
        // Look for patterns like "ABC/2020/001 - First Class" or "ABC/2020/001: Second Class Upper"
        $patterns = [
            '/([A-Z]{2,4}\/\d{4}\/\d{3,4})\s*[-:]\s*([^,\n\r]+)/i',
            '/([A-Z]{2,4}\/\d{4}\/\d{3,4})\s+([^,\n\r]+)/i'
        ];
        
        foreach ($patterns as $pattern) {
            if ($text !== '' && preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $matricNo = trim($match[1]);
                    $classOfDegree = trim($match[2]);
                    
                    $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                    if ($normalizedDegree) {
                        $data[] = [
                            'matric_no' => strtoupper($matricNo),
                            'class_of_degree' => $normalizedDegree,
                            'source' => 'text',
                            'row_number' => null
                        ];
                    }
                }
            }
        }
        
        return $data;
    }

    /**
     * Perform full text scan of the entire document
     * This catches any data that might be missed by structured extraction
     *
     * @param \PhpOffice\PhpWord\PhpWord $phpWord
     * @param array &$extractedData
     * @return void
     */
    protected function performFullTextScan($phpWord, &$extractedData): void
    {
        try {
            Log::info('Starting full text scan for missed records');
            
            $allText = '';
            
            // Extract all text from all sections
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $allText .= $this->extractAllTextFromElement($element) . "\n";
                }
            }
            
            // Split text into lines and scan each line
            $lines = explode("\n", $allText);
            $lineNumber = 0;
            
            foreach ($lines as $line) {
                $lineNumber++;
                $line = trim($line);
                
                if (empty($line)) continue;
                
                // Multiple patterns to catch different formats
                $patterns = [
                    // Standard format: ABC/DEF/20/1234 First Class
                    '/([A-Z]{2,4}\/[A-Z]{2,4}\/\d{2}\/\d{3,4})\s+(.+?)(?=\s+[A-Z]{2,4}\/[A-Z]{2,4}\/\d{2}\/\d{3,4}|$)/i',
                    // With separators: ABC/DEF/20/1234 - First Class
                    '/([A-Z]{2,4}\/[A-Z]{2,4}\/\d{2}\/\d{3,4})\s*[-:]\s*([^,\n\r]+)/i',
                    // Tabulated format
                    '/([A-Z]{2,4}\/[A-Z]{2,4}\/\d{2}\/\d{3,4})\s+([^0-9\n\r]+?)(?=\s*$|\s+\d|\s+[A-Z]{2,4}\/)/i',
                    // Alternative separators
                    '/([A-Z]{2,4}[-_][A-Z]{2,4}[-_]\d{2}[-_]\d{3,4})\s+(.+?)(?=\s+[A-Z]{2,4}[-_]|$)/i',
                ];
                
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $matricNo = trim($match[1]);
                            $classOfDegree = trim($match[2]);
                            
                            // Clean up the class of degree
                            $classOfDegree = preg_replace('/\s+/', ' ', $classOfDegree);
                            $classOfDegree = trim($classOfDegree, '.,;');
                            
                            $normalizedDegree = $this->normalizeClassOfDegree($classOfDegree);
                            if ($normalizedDegree && !empty($matricNo)) {
                                // Check if this record already exists
                                $exists = false;
                                foreach ($extractedData as $existing) {
                                    if (strtoupper($existing['matric_no']) === strtoupper($matricNo)) {
                                        $exists = true;
                                        break;
                                    }
                                }
                                
                                if (!$exists) {
                                    $extractedData[] = [
                                        'matric_no' => strtoupper($matricNo),
                                        'class_of_degree' => $normalizedDegree,
                                        'source' => 'fulltext_scan',
                                        'row_number' => $lineNumber
                                    ];
                                }
                            }
                        }
                    }
                }
            }
            
            Log::info('Full text scan completed', ['total_lines_scanned' => $lineNumber]);
            
        } catch (\Exception $e) {
            Log::error('Error in full text scan', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Extract all text from any element recursively
     *
     * @param mixed $element
     * @return string
     */
    protected function extractAllTextFromElement($element): string
    {
        $text = '';
        
        try {
            if (method_exists($element, 'getText')) {
                $t = $element->getText();
                if (is_string($t)) { $text .= $t . ' '; }
            }
            
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                foreach ($element->getElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $tt = $textElement->getText();
                        if (is_string($tt)) { $text .= $tt . ' '; }
                    }
                }
            }
            
            if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $text .= $this->getCellText($cell) . ' ';
                    }
                }
            }
            
            // Handle other element types
            if (method_exists($element, 'getElements')) {
                foreach ($element->getElements() as $subElement) {
                    $text .= $this->extractAllTextFromElement($subElement) . ' ';
                }
            }
            
        } catch (\Exception $e) {
            // Continue processing even if one element fails
        }
        
        return $text;
    }

    /**
     * Remove duplicate records based on matric_no
     *
     * @param array $data
     * @return array
     */
    protected function removeDuplicateRecords(array $data): array
    {
        $unique = [];
        $seen = [];
        
        foreach ($data as $record) {
            $matricKey = strtoupper($record['matric_no']);
            
            if (!isset($seen[$matricKey])) {
                $unique[] = $record;
                $seen[$matricKey] = true;
            }
        }
        
        Log::info('Duplicate removal completed', [
            'original_count' => count($data),
            'unique_count' => count($unique),
            'duplicates_removed' => count($data) - count($unique)
        ]);
        
        return $unique;
    }

    /**
     * Get text content from a table cell
     *
     * @param mixed $cell
     * @return string
     */
    protected function getCellText($cell): string
    {
        $text = '';
        
        foreach ($cell->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $t = $element->getText();
                if (is_string($t)) { $text .= $t . ' '; }
            } elseif ($element instanceof TextRun) {
                foreach ($element->getElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $tt = $textElement->getText();
                        if (is_string($tt)) { $text .= $tt . ' '; }
                    }
                }
            }
        }
        
        return trim($text);
    }

    /**
     * Normalize class of degree to standard format
     *
     * @param string $degree
     * @return string|null
     */
    protected function normalizeClassOfDegree(string $degree): ?string
    {
        $degree = trim($degree);
        $lowerDegree = strtolower($degree);
        
        // Mapping variations to standard format
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
     * Validate class of degree against allowed values
     *
     * @param string $degree
     * @return bool
     */
    public function validateClassOfDegree(string $degree): bool
    {
        return in_array($degree, $this->validClassOfDegree);
    }

    /**
     * Match extracted data with existing students
     * Returns ALL extracted records with their matching status
     *
     * @param array $extractedData
     * @return array
     */
    public function matchWithExistingStudents(array $extractedData): array
    {
        $allData = [];
        $matchedCount = 0;
        
        // Get all student matric numbers for fuzzy matching (normalized)
        $allDbMatrics = StudentNysc::pluck('matric_no')->map(function($matric) {
            return strtoupper(trim(preg_replace('/\s+/', '', $matric)));
        })->toArray();
        
        foreach ($extractedData as $data) {
            $matricNo = $data['matric_no'];
            
            // Try multiple variations for exact matching
            $student = $this->findExactMatch($matricNo);
            
            if ($student) {
                // Exact match found
                $allData[] = [
                    'student_id' => $student->id,
                    'matric_no' => $matricNo,
                    'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'current_class_of_degree' => $student->class_of_degree,
                    'proposed_class_of_degree' => $data['class_of_degree'],
                    'match_confidence' => 'exact',
                    'source' => $data['source'],
                    'row_number' => $data['row_number'],
                    'is_matched' => true,
                    'match_type' => 'exact'
                ];
                
                $matchedCount++;
                
                Log::info('Student matched (exact)', [
                    'matric_no' => $matricNo,
                    'student_id' => $student->id
                ]);
            } else {
                // Try fuzzy matching with normalized matric number
                $normalizedMatricNo = strtoupper(trim(preg_replace('/\s+/', '', $matricNo)));
                $similarMatric = $this->findSimilarMatricNumber($normalizedMatricNo, $allDbMatrics);
                
                if ($similarMatric) {
                    // Find the student with the similar matric (need to reverse lookup since we normalized)
                    $similarStudent = StudentNysc::whereRaw('UPPER(TRIM(REPLACE(matric_no, " ", ""))) = ?', [$similarMatric])->first();
                    
                    if ($similarStudent) {
                        // Similar match found
                        $allData[] = [
                            'student_id' => $similarStudent->id,
                            'matric_no' => $matricNo,
                            'student_name' => trim(($similarStudent->fname ?? '') . ' ' . ($similarStudent->mname ?? '') . ' ' . ($similarStudent->lname ?? '')),
                            'current_class_of_degree' => $similarStudent->class_of_degree,
                            'proposed_class_of_degree' => $data['class_of_degree'],
                            'match_confidence' => 'similar',
                            'source' => $data['source'],
                            'row_number' => $data['row_number'],
                            'is_matched' => true,
                            'match_type' => 'similar',
                            'db_matric_no' => $similarStudent->matric_no,
                            'similarity_type' => $this->getSimilarityType($normalizedMatricNo, $similarMatric)
                        ];
                        
                        $matchedCount++;
                        
                        Log::info('Student matched (similar)', [
                            'graduands_matric' => $matricNo,
                            'db_matric' => $similarStudent->matric_no,
                            'student_id' => $similarStudent->id
                        ]);
                    } else {
                        // This shouldn't happen, but handle it
                        $allData[] = $this->createUnmatchedRecord($data, $matricNo);
                    }
                } else {
                    // No match found at all
                    $allData[] = $this->createUnmatchedRecord($data, $matricNo);
                    Log::info('Student NOT found in database', ['matric_no' => $matricNo]);
                }
            }
        }
        
        Log::info('Matching completed', [
            'total_extracted' => count($extractedData),
            'total_matched' => $matchedCount,
            'total_unmatched' => count($extractedData) - $matchedCount
        ]);
        
        return $allData;
    }

    /**
     * Prepare all data (matched and unmatched) for admin review
     *
     * @param array $allData
     * @return array
     */
    public function prepareReviewData(array $allData): array
    {
        $reviewData = [];
        
        foreach ($allData as $data) {
            $dbVal = $data['current_class_of_degree'];
            $propVal = $data['proposed_class_of_degree'];
            $dbHas = $dbVal !== null && $dbVal !== '';
            $propHas = $propVal !== null && $propVal !== '';
            $normDb = $dbHas ? ($this->normalizeClassOfDegree($dbVal) ?? trim($dbVal)) : null;
            $normProp = $propHas ? ($this->normalizeClassOfDegree($propVal) ?? trim($propVal)) : null;
            $equal = (!$dbHas && !$propHas) || ($normDb !== null && $normProp !== null && strcasecmp($normDb, $normProp) === 0);
            $needsUpdate = ($data['is_matched'] ?? false) && !$equal;

            $reviewData[] = [
                'student_id' => $data['student_id'],
                'matric_no' => $data['matric_no'],
                'student_name' => $data['student_name'],
                'current_class_of_degree' => $data['current_class_of_degree'],
                'proposed_class_of_degree' => $data['proposed_class_of_degree'],
                'match_confidence' => $data['match_confidence'],
                'is_matched' => $data['is_matched'] ?? false,
                'match_type' => $data['match_type'] ?? 'unknown',
                'needs_update' => $needsUpdate,
                'approved' => false,
                'source' => $data['source'],
                'row_number' => $data['row_number']
            ];
        }
        
        return $reviewData;
    }

    /**
     * Apply approved updates to the database
     *
     * @param array $approvedData
     * @return array
     */
    public function applyApprovedUpdates(array $approvedData): array
    {
        $updateCount = 0;
        $errorCount = 0;
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
                'error_count' => $errorCount
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
            'errors' => $errors
        ];
    }

    /**
     * Find exact match with various formatting variations
     * Handles case sensitivity, spaces, and other minor formatting differences
     *
     * @param string $matricNo
     * @return StudentNysc|null
     */
    private function findExactMatch(string $matricNo): ?StudentNysc
    {
        // Create variations of the matric number to try
        $variations = [
            $matricNo,                                    // Original
            strtoupper($matricNo),                       // Upper case
            strtolower($matricNo),                       // Lower case
            trim($matricNo),                             // Trimmed
            strtoupper(trim($matricNo)),                 // Upper case + trimmed
            preg_replace('/\s+/', '', $matricNo),        // Remove all spaces
            strtoupper(preg_replace('/\s+/', '', $matricNo)), // Upper case + no spaces
            preg_replace('/\s+/', ' ', trim($matricNo)), // Normalize spaces
            strtoupper(preg_replace('/\s+/', ' ', trim($matricNo))), // Upper + normalized spaces
        ];
        
        // Also handle spaces before the last digits (e.g., "VUG/CSC/21/ 5732" -> "VUG/CSC/21/5732")
        $spaceBeforeDigits = preg_replace('/\/\s+(\d+)$/', '/$1', $matricNo);
        if ($spaceBeforeDigits !== $matricNo) {
            $variations[] = $spaceBeforeDigits;
            $variations[] = strtoupper($spaceBeforeDigits);
            $variations[] = strtolower($spaceBeforeDigits);
        }
        
        // Remove duplicates
        $variations = array_unique($variations);
        
        // Try each variation
        foreach ($variations as $variation) {
            $student = StudentNysc::where('matric_no', $variation)->first();
            if ($student) {
                Log::info('Exact match found', [
                    'original' => $matricNo,
                    'matched_with' => $variation,
                    'student_id' => $student->id
                ]);
                return $student;
            }
        }
        
        // Also try case-insensitive search as a last resort for exact matching
        $student = StudentNysc::whereRaw('UPPER(TRIM(REPLACE(matric_no, " ", ""))) = ?', [
            strtoupper(trim(preg_replace('/\s+/', '', $matricNo)))
        ])->first();
        
        if ($student) {
            Log::info('Exact match found (case-insensitive + normalized)', [
                'original' => $matricNo,
                'matched_with' => $student->matric_no,
                'student_id' => $student->id
            ]);
            return $student;
        }
        
        return null;
    }

    /**
     * Create an unmatched record entry
     *
     * @param array $data
     * @param string $matricNo
     * @return array
     */
    private function createUnmatchedRecord(array $data, string $matricNo): array
    {
        return [
            'student_id' => null,
            'matric_no' => $matricNo,
            'student_name' => $data['student_name'] ?? 'Unknown',
            'current_class_of_degree' => null,
            'proposed_class_of_degree' => null,
            'match_confidence' => 'none',
            'source' => $data['source'],
            'row_number' => $data['row_number'],
            'is_matched' => false,
            'match_type' => 'unmatched'
        ];
    }

    /**
     * Find similar matric number using fuzzy matching
     * Focuses on matching the final student number (e.g., 7194) and handles case-insensitive department matching
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