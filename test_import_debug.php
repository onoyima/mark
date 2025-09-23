<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Laravel environment
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\ExcelImportService;
use Illuminate\Support\Facades\Log;

echo "\n===== NYSC Import Debug Test =====\n";

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

if (count($listData) === 0) {
    echo "ERROR: List data is empty. Check if list.csv exists and is properly formatted.\n";
}

if (count($responseData) === 0) {
    echo "ERROR: Response data is empty. Check if Response.csv exists and is properly formatted.\n";
}

// Show sample data from both files
echo "\n2. Sample data from CSV files:\n";

echo "\nList data sample:\n";
$i = 0;
foreach ($listData as $key => $value) {
    echo "Key: $key\n";
    echo "Value: " . json_encode($value, JSON_PRETTY_PRINT) . "\n";
    if (++$i >= 2) break; // Only show first 2 entries
}

echo "\nResponse data sample:\n";
$i = 0;
foreach ($responseData as $key => $value) {
    echo "Key: $key\n";
    echo "Value: " . json_encode($value, JSON_PRETTY_PRINT) . "\n";
    if (++$i >= 2) break; // Only show first 2 entries
}

// Test eligible records function
echo "\n3. Testing getEligibleRecordsForImport()...\n";
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
} else {
    echo "No eligible records found. Debugging why...\n";
    
    // Debug why no eligible records are found
    echo "\n4. Debugging eligible records issue...\n";
    
    // Check for matching matric numbers in both files
    echo "\nChecking for matching matric numbers in both files...\n";
    $matchCount = 0;
    foreach ($listData as $listMatricNo => $listValue) {
        if (isset($responseData[$listMatricNo])) {
            echo "Match found: $listMatricNo\n";
            $matchCount++;
            if ($matchCount >= 3) break; // Only show first 3 matches
        }
    }
    echo "Total matches found: $matchCount\n";
    
    if ($matchCount === 0) {
        echo "ERROR: No matching matric numbers found between list.csv and Response.csv\n";
        echo "This could be due to case sensitivity issues or format differences.\n";
    }
    
    // Test student lookup in database
    echo "\nTesting student lookup in database...\n";
    foreach ($listData as $matricNo => $listValue) {
        if (isset($responseData[$matricNo])) {
            // Find student with this matric number (case-insensitive)
            $academicRecord = \App\Models\StudentAcademic::whereRaw('UPPER(matric_no) = ?', [strtoupper($matricNo)])->first();
            
            if ($academicRecord) {
                echo "Found academic record for: $matricNo (ID: {$academicRecord->id})\n";
                
                $student = \App\Models\Student::find($academicRecord->student_id);
                if ($student) {
                    echo "Found student: {$student->fname} {$student->lname} (ID: {$student->id})\n";
                    
                    // Check if student already has NYSC data
                    $existingRecord = \App\Models\StudentNysc::where('student_id', $student->id)
                        ->orWhere('matric_no', $matricNo)
                        ->first();
                    
                    if ($existingRecord) {
                        echo "Student already has NYSC record (ID: {$existingRecord->id})\n";
                    } else {
                        echo "Student does not have NYSC record yet\n";
                        
                        // Test data preparation
                        echo "\nTesting prepareStudentData() for $matricNo...\n";
                        $preparedData = $importService->prepareStudentData($student->id, $matricNo);
                        
                        if ($preparedData) {
                            echo "Data prepared successfully:\n";
                            echo json_encode($preparedData, JSON_PRETTY_PRINT) . "\n";
                            
                            // Check DOB and graduation_year fields specifically
                            echo "\nChecking critical fields:\n";
                            echo "DOB: " . ($preparedData['dob'] ?? 'NULL') . "\n";
                            echo "Graduation Year: " . ($preparedData['graduation_year'] ?? 'NULL') . "\n";
                            
                            // If either is NULL, this could be causing the import to fail
                            if ($preparedData['dob'] === null) {
                                echo "WARNING: DOB is NULL - This might be causing import issues\n";
                                
                                // Show original value from response data
                                $originalDob = $responseData[$matricNo]['Date of Birth:'] ?? 'Not set';
                                echo "Original DOB value: $originalDob\n";
                            }
                            
                            if ($preparedData['graduation_year'] === null) {
                                echo "WARNING: Graduation Year is NULL - This might be causing import issues\n";
                                
                                // Show original value from response data
                                $originalYear = $responseData[$matricNo]['Date of Graduation:'] ?? 'Not set';
                                echo "Original Graduation Year value: $originalYear\n";
                            }
                        } else {
                            echo "Failed to prepare data for student\n";
                        }
                        
                        break; // Only test one eligible student
                    }
                } else {
                    echo "ERROR: No student found with ID: {$academicRecord->student_id}\n";
                }
                
                break; // Only test one matching student
            } else {
                echo "No academic record found for: $matricNo\n";
            }
        }
    }
}

echo "\n===== Test Complete =====\n";