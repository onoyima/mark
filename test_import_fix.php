<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel environment
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\ExcelImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

echo "\n===== NYSC Import Fix Test =====\n";

// Create an instance of the ExcelImportService
$importService = new ExcelImportService();

// Test if CSV files are loaded correctly
echo "\n1. Testing CSV file loading...\n";

// Use reflection to access protected properties
$reflection = new ReflectionClass($importService);

$listDataProperty = $reflection->getProperty('listData');
$listDataProperty->setAccessible(true);
$listData = $listDataProperty->getValue($importService);

$responseDataProperty = $reflection->getProperty('responseData');
$responseDataProperty->setAccessible(true);
$responseData = $responseDataProperty->getValue($importService);

echo "List data count: " . count($listData) . "\n";
echo "Response data count: " . count($responseData) . "\n";

// Test eligible records function
echo "\n2. Testing getEligibleRecordsForImport()...\n";
$eligibleRecords = $importService->getEligibleRecordsForImport();
echo "Eligible records count: " . count($eligibleRecords) . "\n";

if (count($eligibleRecords) > 0) {
    echo "\nSample eligible record:\n";
    echo json_encode($eligibleRecords[0], JSON_PRETTY_PRINT) . "\n";
    
    // Get the matric number from the eligible record
    $matricNo = $eligibleRecords[0]['matric_no'];
    echo "\nChecking raw data for matric number: $matricNo\n";
    
    // Check if this matric number exists in the response data
    if (isset($responseData[$matricNo])) {
        echo "Found in response data:\n";
        echo json_encode($responseData[$matricNo], JSON_PRETTY_PRINT) . "\n";
        
        // Check the DOB and graduation year values specifically
        echo "\nRaw DOB value: " . ($responseData[$matricNo]['Date of Birth:'] ?? 'Not set') . "\n";
        echo "Raw Graduation Year value: " . ($responseData[$matricNo]['Date of Graduation:'] ?? 'Not set') . "\n";
    } else {
        // Try case-insensitive search
        $found = false;
        foreach ($responseData as $key => $value) {
            if (strtoupper($key) === strtoupper($matricNo)) {
                echo "Found in response data (case-insensitive match):\n";
                echo "Key in response data: $key\n";
                echo json_encode($value, JSON_PRETTY_PRINT) . "\n";
                
                // Check the DOB and graduation year values specifically
                echo "\nRaw DOB value: " . ($value['Date of Birth:'] ?? 'Not set') . "\n";
                echo "Raw Graduation Year value: " . ($value['Date of Graduation:'] ?? 'Not set') . "\n";
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            echo "ERROR: Matric number not found in response data!\n";
        }
    }
    
    // Test the actual import process
    echo "\n3. Testing import process...\n";
    
    // First, delete any existing record for this student to ensure clean test
    $studentId = $eligibleRecords[0]['student_id'];
    DB::table('student_nysc')->where('student_id', $studentId)->orWhere('matric_no', $matricNo)->delete();
    echo "Deleted any existing records for student ID: $studentId, Matric No: $matricNo\n";
    
    // Enable query logging
    DB::enableQueryLog();
    
    // Create a complete test record with all required fields
    $testRecord = [
        'student_id' => $studentId,
        'matric_no' => $matricNo,
        'is_paid' => false,
        'is_submitted' => false,
        'fname' => 'Test',
        'lname' => 'Student',
        'gender' => 'Male', // Valid enum value
        'marital_status' => 'Single', // Valid enum value
        'course_study' => 'Computer Science',
        'department' => 'Computer Science',
        'cgpa' => 3.5,
    ];
    
    // Test direct import
    echo "Testing direct import with complete record...\n";
    $directResult = $importService->importStudentData($testRecord);
    
    echo "Direct import result: " . ($directResult ? "SUCCESS" : "FAILED") . "\n";
    
    // Check if the record was inserted
    $record = DB::table('student_nysc')->where('student_id', $studentId)->orWhere('matric_no', $matricNo)->first();
    
    if ($record) {
        echo "\nRecord successfully inserted into database:\n";
        echo "ID: {$record->id}\n";
        echo "Student ID: {$record->student_id}\n";
        echo "Matric No: {$record->matric_no}\n";
        echo "DOB: " . ($record->dob ?? 'NULL') . "\n";
        echo "Graduation Year: " . ($record->graduation_year ?? 'NULL') . "\n";
    } else {
        echo "\nERROR: Record was not directly inserted into database!\n";
        
        // Show the last query executed
        $queries = DB::getQueryLog();
        if (!empty($queries)) {
            $lastQuery = end($queries);
            echo "Last SQL query: " . $lastQuery['query'] . "\n";
            echo "Query bindings: " . json_encode($lastQuery['bindings']) . "\n";
        }
    }
    
    // Delete the record for the next test
    DB::table('student_nysc')->where('student_id', $studentId)->orWhere('matric_no', $matricNo)->delete();
    
    // Now test the full import process
    echo "\nTesting full import process...\n";
    $result = $importService->processResponseData($responseData, $listData);
    echo "Import result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    
    // Check if the record was actually inserted
    $record = DB::table('student_nysc')->where('student_id', $studentId)->orWhere('matric_no', $matricNo)->first();
    
    if ($record) {
        echo "\nRecord successfully inserted into database:\n";
        echo "ID: {$record->id}\n";
        echo "Student ID: {$record->student_id}\n";
        echo "Matric No: {$record->matric_no}\n";
        echo "DOB: " . ($record->dob ?? 'NULL') . "\n";
        echo "Graduation Year: " . ($record->graduation_year ?? 'NULL') . "\n";
    } else {
        echo "\nERROR: Record was not inserted into database!\n";
        
        // Show the last query executed
        $queries = DB::getQueryLog();
        if (!empty($queries)) {
            $lastQuery = end($queries);
            echo "Last SQL query: " . $lastQuery['query'] . "\n";
            echo "Query bindings: " . json_encode($lastQuery['bindings']) . "\n";
        }
    }
} else {
    echo "No eligible records found. Cannot test import process.\n";
}

echo "\n===== Test Complete =====\n";