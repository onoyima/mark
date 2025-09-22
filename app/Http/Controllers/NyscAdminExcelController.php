<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\ExcelImportService;
use App\Models\StudentNysc;

class NyscAdminExcelController extends Controller
{
    protected $excelService;
    
    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
        $this->excelService = new ExcelImportService();
    }
    
    /**
     * Get eligible records for import
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEligibleRecords()
    {
        try {
            $eligibleRecords = $this->excelService->getEligibleRecordsForImport();
            
            return response()->json([
                'success' => true,
                'data' => $eligibleRecords,
                'count' => count($eligibleRecords)
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting eligible records: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get eligible records: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Import selected records
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function importSelectedRecords(Request $request)
    {
        try {
            $matricNumbers = $request->input('matricNumbers', []);
            $imported = 0;
            $failed = 0;
            
            // Log the received data for debugging
            Log::info('Received matricNumbers for import', ['matricNumbers' => $matricNumbers]);
            
            // Get eligible records first
            $eligibleRecords = $this->excelService->getEligibleRecordsForImport();
            
            // Create a lookup array for faster access
            $eligibleLookup = [];
            foreach ($eligibleRecords as $record) {
                if (isset($record['matric_no'])) {
                    $eligibleLookup[$record['matric_no']] = $record;
                }
            }
            
            foreach ($matricNumbers as $matricNo) {
                // Find the record in eligible records
                if (isset($eligibleLookup[$matricNo])) {
                    $record = $eligibleLookup[$matricNo];
                    
                    // Import data
                    $result = $this->excelService->importStudentData($record);
                    
                    if ($result) {
                        $imported++;
                        Log::info('Successfully imported record', ['matric_no' => $matricNo]);
                    } else {
                        $failed++;
                        Log::error('Failed to import record', ['matric_no' => $matricNo]);
                    }
                } else {
                    $failed++;
                    Log::error('Matric number not found in eligible records', ['matric_no' => $matricNo]);
                }
            }
            
            return response()->json([
                'success' => true,
                'imported' => $imported,
                'failed' => $failed,
                'message' => "Successfully imported {$imported} records. {$failed} records failed."
            ]);
        } catch (\Exception $e) {
            Log::error('Error importing selected records: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to import records: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Import all eligible records
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importAllEligibleRecords()
    {
        try {
            $eligibleRecords = $this->excelService->getEligibleRecordsForImport();
            $imported = 0;
            $failed = 0;
            
            // Log the number of eligible records found
            Log::info('Attempting to import all eligible records', ['count' => count($eligibleRecords)]);
            
            foreach ($eligibleRecords as $record) {
                // Import data
                $result = $this->excelService->importStudentData($record);
                
                if ($result) {
                    $imported++;
                    Log::info('Successfully imported record', ['matric_no' => $record['matric_no'] ?? 'unknown']);
                } else {
                    $failed++;
                    Log::error('Failed to import record', ['matric_no' => $record['matric_no'] ?? 'unknown']);
                }
            }
            
            return response()->json([
                'success' => true,
                'imported' => $imported,
                'failed' => $failed,
                'message' => "Successfully imported {$imported} records. {$failed} records failed."
            ]);
        } catch (\Exception $e) {
            Log::error('Error importing all eligible records: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to import records: ' . $e->getMessage()
            ], 500);
        }
    }
}