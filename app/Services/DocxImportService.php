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
            
            return [
                'success' => true,
                'session_id' => Str::uuid()->toString(),
                'summary' => [
                    'total_extracted' => count($extractedData),
                    'total_matched' => count($matchedData),
                    'ready_for_review' => count($reviewData)
                ],
                'review_data' => $reviewData
            ];
            
        } catch (\Exception $e) {
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
            $text = $element->getText();
        } elseif ($element instanceof TextRun) {
            $text = $element->getText();
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
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
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
                $text .= $element->getText() . ' ';
            }
            
            if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                foreach ($element->getElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $text .= $textElement->getText() . ' ';
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
                $text .= $element->getText() . ' ';
            } elseif ($element instanceof TextRun) {
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
     *
     * @param array $extractedData
     * @return array
     */
    public function matchWithExistingStudents(array $extractedData): array
    {
        $matchedData = [];
        
        foreach ($extractedData as $data) {
            $matricNo = $data['matric_no'];
            
            // Find student with matching matric number (case-insensitive)
            $student = StudentNysc::whereRaw('UPPER(matric_no) = ?', [strtoupper($matricNo)])->first();
            
            if ($student) {
                $matchedData[] = [
                    'student_id' => $student->id,
                    'matric_no' => $matricNo,
                    'student_name' => trim(($student->fname ?? '') . ' ' . ($student->mname ?? '') . ' ' . ($student->lname ?? '')),
                    'current_class_of_degree' => $student->class_of_degree,
                    'proposed_class_of_degree' => $data['class_of_degree'],
                    'match_confidence' => 'exact',
                    'source' => $data['source'],
                    'row_number' => $data['row_number']
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
     *
     * @param array $matchedData
     * @return array
     */
    public function prepareReviewData(array $matchedData): array
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
}