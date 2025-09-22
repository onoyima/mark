<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Student;
use App\Models\StudentAcademic;
use App\Models\StudentNysc;
use App\Models\CourseStudy;
use Illuminate\Support\Facades\DB;

class ExcelImportService
{
    protected $listFilePath;
    protected $responseFilePath;
    protected $listData = [];
    protected $responseData = [];

    public function __construct()
    {
        $this->listFilePath = storage_path('app/list.csv');
        $this->responseFilePath = storage_path('app/response.csv');
        
        // Copy CSV files to storage if they don't exist there
        $this->copyCSVFilesToStorage();
        
        // Load data from CSV files
        $this->loadCSVData();
    }

    /**
     * Copy CSV files from project root to storage for easier access
     */
    protected function copyCSVFilesToStorage()
    {
        $projectRoot = base_path();
        
        // Ensure storage directory exists
        if (!file_exists(storage_path('app'))) {
            mkdir(storage_path('app'), 0755, true);
        }
        
        // Copy list.csv if it doesn't exist in storage
        if (!file_exists($this->listFilePath) && file_exists($projectRoot . '/list.csv')) {
            copy($projectRoot . '/list.csv', $this->listFilePath);
        }
        
        // Copy Response.csv if it doesn't exist in storage
        if (!file_exists($this->responseFilePath) && file_exists($projectRoot . '/Response.csv')) {
            copy($projectRoot . '/Response.csv', $this->responseFilePath);
        } elseif (!file_exists($this->responseFilePath) && file_exists($projectRoot . '/response.csv')) {
            // Also check for lowercase filename
            copy($projectRoot . '/response.csv', $this->responseFilePath);
        }
    }

    /**
     * Load data from CSV files
     */
    protected function loadCSVData()
    {
        try {
            // Load list.csv
            if (file_exists($this->listFilePath)) {
                $handle = fopen($this->listFilePath, 'r');
                
                // Assuming first row is header
                $headers = fgetcsv($handle);
                
                while (($row = fgetcsv($handle)) !== false) {
                    // Create associative array using headers
                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        if (isset($row[$index])) {
                            $rowData[$header] = $row[$index];
                        }
                    }
                    
                    // Add to list data if it has a matric number (header is 'MATRIC NO')
                    if (!empty($rowData['MATRIC NO'])) {
                        // Store with uppercase key for case-insensitive matching
                        $this->listData[strtoupper($rowData['MATRIC NO'])] = $rowData;
                    }
                }
                
                fclose($handle);
            }
            
            // Load Response.csv
            if (file_exists($this->responseFilePath)) {
                $handle = fopen($this->responseFilePath, 'r');
                
                // Assuming first row is header
                $headers = fgetcsv($handle);
                
                // Initialize temporary array to store entries by matric number with timestamps
                $tempResponseData = [];
                
                while (($row = fgetcsv($handle)) !== false) {
                    // Create associative array using headers
                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        if (isset($row[$index])) {
                            $rowData[$header] = $row[$index];
                        }
                    }
                    
                    // Add to response data if it has a matric number (header is 'Matric No:')
                    if (!empty($rowData['Matric No:'])) {
                        // Convert to uppercase for case-insensitive matching
                        $matricNo = strtoupper($rowData['Matric No:']);
                        
                        // Get timestamp from the first column (the header is 'Timestamp')
                        $timestamp = !empty($rowData['Timestamp']) ? strtotime($rowData['Timestamp']) : 0;
                        
                        // Store entry with its timestamp
                        if (!isset($tempResponseData[$matricNo])) {
                            $tempResponseData[$matricNo] = [];
                        }
                        
                        $tempResponseData[$matricNo][] = [
                            'timestamp' => $timestamp,
                            'data' => $rowData
                        ];
                    }
                }
                
                // Process the temporary data to keep only the latest entry for each matric number
                foreach ($tempResponseData as $matricNo => $entries) {
                    // Sort entries by timestamp in descending order (latest first)
                    usort($entries, function($a, $b) {
                        return $b['timestamp'] - $a['timestamp'];
                    });
                    
                    // Use the latest entry (first after sorting)
                    $this->responseData[$matricNo] = $entries[0]['data'];
                }
                
                fclose($handle);
            }
        } catch (\Exception $e) {
            Log::error('Error loading Excel data: ' . $e->getMessage());
        }
    }

    /**
     * Check if a student's matric number exists in the list.csv file
     * Case-insensitive matching
     *
     * @param string $matricNo
     * @return bool
     */
    public function checkStudentInList($matricNo)
    {
        // Convert to uppercase for case-insensitive matching
        $upperMatricNo = strtoupper($matricNo);
        $result = isset($this->listData[$upperMatricNo]);
        
        // Log the result for debugging
        if ($result) {
            Log::info("Match found in list data for: " . $matricNo);
        }
        
        return $result;
    }

    /**
     * Get student data from Response.csv if matric number exists in both list.csv and Response.csv
     * Returns the latest data for the given matric number
     *
     * @param string $matricNo
     * @return array|null
     */
    public function getStudentDataFromResponse($matricNo)
    {
        // Convert to uppercase for case-insensitive matching
        $upperMatricNo = strtoupper($matricNo);
        if ($this->checkStudentInList($matricNo) && isset($this->responseData[$upperMatricNo])) {
            return $this->responseData[$upperMatricNo];
        }
        
        return null;
    }
    
    /**
     * Get all eligible records for admin review
     * 
     * @return array
     */
    public function getEligibleRecordsForImport()
    {
        $eligibleRecords = [];
        
        // Log the number of entries in CSV files for debugging
        Log::info("List data count: " . count($this->listData));
        Log::info("Response data count: " . count($this->responseData));
        
        if (count($this->listData) === 0) {
            Log::error("ERROR: List data is empty. Check if list.csv exists and is properly formatted.");
            return $eligibleRecords;
        }
        
        if (count($this->responseData) === 0) {
            Log::error("ERROR: Response data is empty. Check if Response.csv exists and is properly formatted.");
            return $eligibleRecords;
        }
        
        // Debug the first few entries in listData to verify format
        $i = 0;
        foreach ($this->listData as $key => $value) {
            Log::info("List data entry: Key=" . $key . ", Value=" . json_encode($value));
            if (++$i >= 3) break; // Only log first 3 entries
        }
        
        // Alternative approach: Start with the CSV data and find matching students
        // This ensures we're working with matric numbers that definitely exist in the CSV files
        foreach ($this->listData as $upperMatricNo => $listData) {
            // Skip if this matric number is not in Response.csv
            if (!isset($this->responseData[$upperMatricNo])) {
                // Skip mismatches silently without logging
                continue;
            }
            
            // Find student with this matric number (case-insensitive)
            $academicRecord = StudentAcademic::whereRaw('UPPER(matric_no) = ?', [strtoupper($upperMatricNo)])->first();
            
            if (!$academicRecord) {
                // Skip silently if no academic record found
                continue;
            }
            
            $student = Student::find($academicRecord->student_id);
            if (!$student) {
                // Skip silently if no student found
                continue;
            }
            
            // Check if student already has NYSC data
            $existingRecord = StudentNysc::where('student_id', $student->id)
                ->orWhere('matric_no', $upperMatricNo)
                ->first();
            
            // Skip if student already has NYSC data
            if ($existingRecord) {
                continue;
            }
            
            // Prepare data for this student
            $preparedData = $this->prepareStudentData($student->id, $upperMatricNo);
            
            if ($preparedData) {
                // Add student info for display
                $preparedData['student_name'] = $student->fname . ' ' . $student->lname;
                $eligibleRecords[] = $preparedData;
                Log::info("Added eligible record for: " . $upperMatricNo);
            }
        }
        
        // Log the number of eligible records found
        Log::info("Total eligible records found: " . count($eligibleRecords));
        
        return $eligibleRecords;
    }
    
    /**
     * Get eligible records from response data and list data
     * 
     * @param array $responseData
     * @param array $listData
     * @return array
     */
    public function getEligibleRecords($responseData, $listData)
    {
        $eligibleRecords = [];
        
        // Find matric numbers that exist in both list.csv and Response.csv
        foreach ($this->responseData as $matricNo => $data) {
            if ($this->checkStudentInList($matricNo)) {
                // Check if student already has NYSC data
                $existingRecord = StudentNysc::where('matric_no', $matricNo)->first();
                
                // Skip if student already has NYSC data
                if ($existingRecord) {
                    continue;
                }
                
                // Add to eligible records
                $eligibleRecords[$matricNo] = $data;
            }
        }
        
        return $eligibleRecords;
    }
    
    /**
     * Process response data and import into database
     * 
     * @param array $responseData
     * @param array $listData
     * @return array
     */
    public function processResponseData($responseData, $listData)
    {
        $importCount = 0;
        $errorCount = 0;
        $eligibleCount = 0;
        $processedMatricNos = [];
        
        // Get eligible records
        $eligibleRecords = $this->getEligibleRecords($responseData, $listData);
        $eligibleCount = count($eligibleRecords);
        
        Log::info('Found ' . $eligibleCount . ' eligible records for import', ['eligible_records' => array_keys($eligibleRecords)]);
        
        // Process each eligible record
        foreach ($eligibleRecords as $matricNo => $data) {
            // Skip if already processed
            if (in_array($matricNo, $processedMatricNos)) {
                continue;
            }
            
            Log::info('Processing student data', ['matric_no' => $matricNo]);
            
            // Get student ID from the database - check in student_academics table
            $academicRecord = StudentAcademic::where('matric_no', $matricNo)->first();
            
            if ($academicRecord) {
                // Even if we can't find the student, we'll use the student_id from the academic record
                Log::info('Found academic record', ['matric_no' => $matricNo, 'student_id' => $academicRecord->student_id]);
                $studentId = $academicRecord->student_id;
            } else {
                Log::error('No student academic record found with matric number', ['matric_no' => $matricNo]);
                $errorCount++;
                continue;
            }
            
            Log::info('Using student ID', ['matric_no' => $matricNo, 'student_id' => $studentId]);
            
            // Prepare student data for import
            $nyscData = $this->prepareStudentData($studentId, $matricNo, $data);
            
            // Skip if no data to import
            if (empty($nyscData)) {
                Log::error('No data prepared for import', ['matric_no' => $matricNo]);
                $errorCount++;
                continue;
            }
            
            // Check if record already exists
            $existingRecord = StudentNysc::where('matric_no', $matricNo)->orWhere('student_id', $studentId)->first();
            if ($existingRecord) {
                Log::info('Record already exists for student', ['matric_no' => $matricNo, 'id' => $existingRecord->id]);
                // Update existing record
                $existingRecord->update($nyscData);
                $importCount++;
            } else {
                // Import student data
                if ($this->importStudentData($nyscData)) {
                    $importCount++;
                } else {
                    $errorCount++;
                }
            }
            
            // Mark as processed
            $processedMatricNos[] = $matricNo;
        }
        
        Log::info('Import summary', [
            'eligible_count' => $eligibleCount,
            'import_count' => $importCount,
            'error_count' => $errorCount
        ]);
        
        return [
            'eligible_count' => $eligibleCount,
            'import_count' => $importCount,
            'error_count' => $errorCount
        ];
    }

    /**
     * Prepare student data from Response.csv for student_nysc table
     *
     * @param int $studentId
     * @param string $matricNo
     * @param array $data Optional data array from Response.csv
     * @return array|bool
     */
    public function prepareStudentData($studentId, $matricNo, $data = null)
    {
        if ($data === null) {
            $data = $this->getStudentDataFromResponse($matricNo);
        }
        
        if (!$data) {
            return false;
        }
        
        try {
            // Check if student already has NYSC data
            $existingRecord = StudentNysc::where('student_id', $studentId)->orWhere('matric_no', $matricNo)->first();
            
            if ($existingRecord) {
                // Data already imported, no need to do it again
                return false;
            }
            
            // Map data from Response.csv to student_nysc table fields
            $nyscData = [
                'student_id' => $studentId,
                'matric_no' => $matricNo,
                'is_paid' => false,
                'is_submitted' => false
            ];
            
            // Direct mapping from Response.csv headers to student_nysc table fields
            $fieldMappings = [
                'First Name:' => 'fname',
                'Surname:' => 'lname',
                'Middle Name:' => 'mname',
                'Gender:' => 'gender',
                'Date of Birth:' => 'dob',
                'Marital Status:' => 'marital_status',
                'GSM No:' => 'phone',
                'State of Origin:' => 'state',
                'Date of Graduation:' => 'graduation_year',
                'Jamb Reg No:' => 'jamb_no',
                'Study Mode:' => 'study_mode'
            ];
            
            // First apply all field mappings
            foreach ($fieldMappings as $responseField => $nyscField) {
                if (isset($data[$responseField])) {
                    $nyscData[$nyscField] = $data[$responseField];
                }
            }
            
            // Fetch the student's academic record to get the actual course of study
            $academicRecord = StudentAcademic::where('student_id', $studentId)->first();
            if ($academicRecord && $academicRecord->course_study_id) {
                // Get the course study name from the course_study table
                $courseStudy = CourseStudy::find($academicRecord->course_study_id);
                if ($courseStudy) {
                    $nyscData['course_study'] = $courseStudy->name;
                    $nyscData['department'] = $courseStudy->name; // Set department to the same value
                    Log::info('Using course of study from database: ' . $courseStudy->name, ['matric_no' => $matricNo]);
                } else {
                    // Fallback to CSV data if course study not found
                    if (isset($data['Course of Study:'])) {
                        $nyscData['course_study'] = $data['Course of Study:'];
                        $nyscData['department'] = $data['Course of Study:'];
                        Log::info('Using course of study from CSV: ' . $data['Course of Study:'], ['matric_no' => $matricNo]);
                    }
                }
            } else {
                // Fallback to CSV data if academic record not found
                if (isset($data['Course of Study:'])) {
                    $nyscData['course_study'] = $data['Course of Study:'];
                    $nyscData['department'] = $data['Course of Study:'];
                    Log::info('Using course of study from CSV (no academic record): ' . $data['Course of Study:'], ['matric_no' => $matricNo]);
                }
            }
            
            // Handle Class of Degree separately to convert to numeric value
            if (isset($data['Class of Degree:'])) {
                $cgpa = $data['Class of Degree:'];
                // Map class honours to numeric values
                $cgpaMap = [
                    'first class' => 4.50,
                    'first class honour' => 4.50,
                    'second class honour upper' => 3.50,
                    'second class upper' => 3.50,
                    'second class honour' => 3.00,
                    'second class lower' => 3.00,
                    'second class honour lower' => 3.00,
                    'third class' => 2.50,
                    'third class honour' => 2.50,
                    'pass' => 2.00
                ];
                
                $lowerCgpa = strtolower($cgpa);
                foreach ($cgpaMap as $class => $value) {
                    if (strpos($lowerCgpa, $class) !== false) {
                        $cgpa = $value;
                        break;
                    }
                }
                
                // Default value if no match found
                if (!is_numeric($cgpa)) {
                    $cgpa = 0.00;
                }
                
                $nyscData['cgpa'] = $cgpa;
            } else if (isset($data['CGPA'])) {
                // Handle CGPA field if present in a different format
                $cgpa = $data['CGPA'];
                if (!is_numeric($cgpa)) {
                    // Apply the same mapping logic for consistency
                    $cgpaMap = [
                        'first class' => 4.50,
                        'first class honour' => 4.50,
                        'second class honour upper' => 3.50,
                        'second class upper' => 3.50,
                        'second class honour' => 3.00,
                        'second class lower' => 3.00,
                        'second class honour lower' => 3.00,
                        'third class' => 2.50,
                        'third class honour' => 2.50,
                        'pass' => 2.00
                    ];
                    
                    $lowerCgpa = strtolower($cgpa);
                    foreach ($cgpaMap as $class => $value) {
                        if (strpos($lowerCgpa, $class) !== false) {
                            $cgpa = $value;
                            break;
                        }
                    }
                    
                    if (!is_numeric($cgpa)) {
                        $cgpa = 0.00;
                    }
                }
                
                $nyscData['cgpa'] = $cgpa;
            }
            
            // Set department to the same value as course_study
            if (isset($data['Course of Study:'])) {
                $nyscData['department'] = $data['Course of Study:'];
            }
            
            // Handle date formatting after applying mappings
            if (isset($nyscData['dob'])) {
                // Keep the original DD/MM/YYYY format as requested
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $nyscData['dob'])) {
                    // Already in the desired format, no need to change
                    // Just ensure it's properly formatted with leading zeros
                    $parts = explode('/', $nyscData['dob']);
                    $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                    $year = $parts[2];
                    $nyscData['dob'] = "$day/$month/$year";
                } else {
                    // Try to convert other formats to DD/MM/YYYY
                    $timestamp = strtotime($nyscData['dob']);
                    if ($timestamp) {
                        $nyscData['dob'] = date('d/m/Y', $timestamp);
                    }
                }
            }
            
            // Process graduation year
            if (isset($nyscData['graduation_year'])) {
                // For graduation_year, we need to extract just the year
                if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $nyscData['graduation_year'], $matches)) {
                    // Extract year from DD/MM/YYYY format
                    $nyscData['graduation_year'] = $matches[3];
                } elseif (preg_match('/^(\d{4})$/', $nyscData['graduation_year'])) {
                    // Already a year format, keep as is
                    $nyscData['graduation_year'] = $nyscData['graduation_year'];
                } elseif (strpos($nyscData['graduation_year'], ',') !== false) {
                    // Handle formats like "November 8, 2025"
                    $timestamp = strtotime($nyscData['graduation_year']);
                    if ($timestamp) {
                        $nyscData['graduation_year'] = date('Y', $timestamp);
                    }
                } else {
                    // Try to extract year from other formats
                    $timestamp = strtotime($nyscData['graduation_year']);
                    if ($timestamp) {
                        $nyscData['graduation_year'] = date('Y', $timestamp);
                    }
                }
            }
            
            // Ensure matric_no is set correctly
            $nyscData['matric_no'] = $matricNo;
            
            // Log the data for debugging
            Log::info('Prepared NYSC data for student', ['matric_no' => $matricNo, 'data' => $nyscData]);
            
            return $nyscData;
        } catch (\Exception $e) {
            Log::error('Error preparing student data: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Import student data from Response.csv to student_nysc table
     *
     * @param array $nyscData
     * @return bool
     */
    public function importStudentData($nyscData)
    {
        try {
            // Log the data being imported
            Log::info('Attempting to import NYSC data', ['data' => $nyscData]);
            
            // Check if student_id is set
            if (empty($nyscData['student_id'])) {
                // Try to find the student through academic records
                $matricNo = $nyscData['matric_no'];
                $academic = DB::table('student_academics')
                    ->where('matric_no', $matricNo)
                    ->first();
                
                if ($academic) {
                    $nyscData['student_id'] = $academic->student_id;
                    Log::info('Found student ID from academic record', ['matric_no' => $matricNo, 'student_id' => $nyscData['student_id']]);
                } else {
                    Log::error('student_id is missing or empty and no academic record found', ['data' => $nyscData]);
                    return false;
                }
            }
            
            // Use DB::table with parameter binding to properly handle string values
            $id = DB::table('student_nysc')->insertGetId([
                'student_id' => $nyscData['student_id'],
                'matric_no' => $nyscData['matric_no'],
                'is_paid' => isset($nyscData['is_paid']) ? $nyscData['is_paid'] : false,
                'is_submitted' => isset($nyscData['is_submitted']) ? $nyscData['is_submitted'] : false,
                'fname' => isset($nyscData['fname']) ? $nyscData['fname'] : '',
                'lname' => isset($nyscData['lname']) ? $nyscData['lname'] : '',
                'mname' => isset($nyscData['mname']) ? $nyscData['mname'] : '',
                'gender' => isset($nyscData['gender']) ? $nyscData['gender'] : '',
                'dob' => isset($nyscData['dob']) ? $nyscData['dob'] : null,
                'marital_status' => isset($nyscData['marital_status']) ? $nyscData['marital_status'] : '',
                'phone' => isset($nyscData['phone']) ? $nyscData['phone'] : '',
                'email' => isset($nyscData['email']) ? $nyscData['email'] : '',
                'address' => isset($nyscData['address']) ? $nyscData['address'] : '',
                'state' => isset($nyscData['state']) ? $nyscData['state'] : '',
                'lga' => isset($nyscData['lga']) ? $nyscData['lga'] : '',
                'username' => isset($nyscData['username']) ? $nyscData['username'] : '',
                'department' => isset($nyscData['department']) ? $nyscData['department'] : '',
                'course_study' => isset($nyscData['course_study']) ? $nyscData['course_study'] : '',
                'level' => isset($nyscData['level']) ? $nyscData['level'] : '',
                'graduation_year' => isset($nyscData['graduation_year']) ? $nyscData['graduation_year'] : '',
                'cgpa' => isset($nyscData['cgpa']) ? $nyscData['cgpa'] : '',
                'jamb_no' => isset($nyscData['jamb_no']) ? $nyscData['jamb_no'] : '',
                'study_mode' => isset($nyscData['study_mode']) ? $nyscData['study_mode'] : '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            Log::info('Imported NYSC data for student', [
                'student_id' => $nyscData['student_id'],
                'matric_no' => $nyscData['matric_no'],
                'id' => $id
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error importing student data: ' . $e->getMessage(), [
                'matric_no' => isset($nyscData['matric_no']) ? $nyscData['matric_no'] : 'unknown',
                'data' => $nyscData,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Clean up duplicate records in Response.csv file
     * Keeps only the most recent record for each matric number based on timestamp
     * 
     * @return bool
     */
    public function cleanupDuplicateRecords()
    {
        try {
            $responseCsvPath = $this->storagePath . '/Response.csv';
            
            if (!file_exists($responseCsvPath)) {
                Log::error('Response.csv file not found at: ' . $responseCsvPath);
                return false;
            }
            
            // Read the CSV file
            $handle = fopen($responseCsvPath, 'r');
            if (!$handle) {
                Log::error('Could not open Response.csv file for reading');
                return false;
            }
            
            // Read header row
            $header = fgetcsv($handle);
            if (!$header) {
                Log::error('Could not read header from Response.csv');
                fclose($handle);
                return false;
            }
            
            // Find the index of the matric_no and timestamp columns
            $matricNoIndex = array_search('Matric No', $header);
            $timestampIndex = array_search('Timestamp', $header);
            
            if ($matricNoIndex === false || $timestampIndex === false) {
                Log::error('Required columns not found in Response.csv');
                fclose($handle);
                return false;
            }
            
            // Read all rows and organize by matric number
            $recordsByMatric = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (isset($row[$matricNoIndex]) && isset($row[$timestampIndex])) {
                    $matricNo = strtolower(trim($row[$matricNoIndex]));
                    $timestamp = strtotime($row[$timestampIndex]);
                    
                    if (!isset($recordsByMatric[$matricNo])) {
                        $recordsByMatric[$matricNo] = [];
                    }
                    
                    $recordsByMatric[$matricNo][] = [
                        'data' => $row,
                        'timestamp' => $timestamp
                    ];
                }
            }
            
            fclose($handle);
            
            // Sort records by timestamp (descending) and keep only the most recent
            $cleanedRecords = [];
            foreach ($recordsByMatric as $matricNo => $records) {
                usort($records, function($a, $b) {
                    return $b['timestamp'] - $a['timestamp']; // Sort by timestamp descending
                });
                
                // Keep only the most recent record
                $cleanedRecords[] = $records[0]['data'];
            }
            
            // Write the cleaned data back to the file
            $handle = fopen($responseCsvPath, 'w');
            if (!$handle) {
                Log::error('Could not open Response.csv file for writing');
                return false;
            }
            
            // Write header
            fputcsv($handle, $header);
            
            // Write cleaned records
            foreach ($cleanedRecords as $record) {
                fputcsv($handle, $record);
            }
            
            fclose($handle);
            
            Log::info('Successfully cleaned up duplicate records in Response.csv', [
                'original_count' => array_sum(array_map('count', $recordsByMatric)),
                'cleaned_count' => count($cleanedRecords)
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error cleaning up duplicate records: ' . $e->getMessage());
            return false;
        }
    }
}