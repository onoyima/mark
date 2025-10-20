<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Models\StudentNysc;

class NyscCsvExportController extends Controller
{
    /**
     * Export student NYSC data to CSV with exact table headers
     *
     * @return Response
     */
    public function exportStudentNyscCsv()
    {
        try {
            Log::info('Starting CSV export of student NYSC data');

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

            $filename = 'student_nysc_data_' . date('Y-m-d_H-i-s') . '.csv';
            
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
                'Pragma' => 'public',
            ];
            
            $callback = function() use ($students) {
                $file = fopen('php://output', 'w');
                
                // Add BOM for proper UTF-8 encoding in Excel
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
                
                // Add headers - exact table column names
                fputcsv($file, [
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
                ]);
                
                // Add data - exact values from database
                foreach ($students as $student) {
                    fputcsv($file, [
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
                    ]);
                }
                
                fclose($file);
            };
            
            Log::info('CSV export completed', ['record_count' => $students->count()]);
            
            return response()->stream($callback, 200, $headers);
            
        } catch (\Exception $e) {
            Log::error('CSV export error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'CSV export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint to verify authentication and controller access
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function test()
    {
        try {
            $user = auth()->user();
            return response()->json([
                'success' => true,
                'message' => 'CSV Export Controller is working',
                'timestamp' => now(),
                'user_id' => $user ? $user->id : null,
                'authenticated' => $user !== null,
                'user_type' => $user ? get_class($user) : null
            ]);
        } catch (\Exception $e) {
            Log::error('CSV Export test endpoint error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get export statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExportStats()
    {
        try {
            $totalRecords = StudentNysc::count();
            $recordsWithClassDegree = StudentNysc::whereNotNull('class_of_degree')->count();
            $recordsWithoutClassDegree = $totalRecords - $recordsWithClassDegree;
            
            return response()->json([
                'success' => true,
                'stats' => [
                    'total_records' => $totalRecords,
                    'records_with_class_degree' => $recordsWithClassDegree,
                    'records_without_class_degree' => $recordsWithoutClassDegree,
                    'completion_percentage' => $totalRecords > 0 ? round(($recordsWithClassDegree / $totalRecords) * 100, 2) : 0
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting export stats', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get export statistics'
            ], 500);
        }
    }
}