<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NyscAuthController;
use App\Http\Controllers\NyscStudentController;
use App\Http\Controllers\NyscPaymentController;
use App\Http\Controllers\NyscAdminController;
use App\Http\Controllers\NyscDocumentController;
use App\Http\Controllers\NyscDuplicatePaymentController;
use App\Http\Controllers\NyscDocxImportController;
use App\Http\Controllers\NyscCsvExportController;

Route::prefix('nysc')->group(function () {

    // ✅ Unified login (student + admin)
    Route::post('login', [NyscAuthController::class, 'login']);

    // ✅ Token verification
    Route::middleware('auth:sanctum')->get('auth/verify', [NyscAuthController::class, 'verify']);
    
    // ✅ Logout
    Route::middleware('auth:sanctum')->post('logout', [NyscAuthController::class, 'logout']);

    // ✅ Student routes (with student token ability)
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('student/details', [NyscStudentController::class, 'getDetails']);
        Route::get('student/analytics', [NyscStudentController::class, 'getAnalytics']);
        Route::post('student/update', [NyscStudentController::class, 'updateDetails']);
        Route::post('student/confirm', [NyscStudentController::class, 'confirmDetails']);
        Route::post('student/submit', [NyscStudentController::class, 'submitDetails']);
        Route::get('student/payment-history', [NyscStudentController::class, 'getPaymentHistory']);
        Route::get('student/profile', [NyscStudentController::class, 'getProfile']);
        Route::put('student/profile', [NyscStudentController::class, 'updateProfile']);
        Route::get('student/study-modes', [NyscStudentController::class, 'getStudyModes']);
        
        // Document management
        Route::get('student/documents', [NyscDocumentController::class, 'getDocuments']);
        Route::post('student/documents/upload', [NyscDocumentController::class, 'uploadDocument']);
        Route::delete('student/documents/{filename}', [NyscDocumentController::class, 'deleteDocument']);

        Route::post('payment', [NyscPaymentController::class, 'initiatePayment']);
        Route::get('payment/verify', [NyscPaymentController::class, 'verifyPayment']);
        Route::get('payment/history', [NyscPaymentController::class, 'getPaymentHistory']);
        Route::get('payment/receipt/{paymentId}', [NyscPaymentController::class, 'getPaymentReceipt']);
        Route::get('student/updated-info', [NyscPaymentController::class, 'getUpdatedStudentInfo']);
    });

    // Paystack webhook (no authentication required)
    Route::post('payment/webhook', [NyscPaymentController::class, 'webhook']);
    
    // Public system status endpoint (no authentication required)
    Route::get('system-status', [NyscAdminController::class, 'getPublicSystemStatus']);
    
    // Temporary test endpoint for pending payments (no auth)
    Route::get('test-pending-payments', [NyscAdminController::class, 'getPendingPaymentsStats']);

    // ✅ Admin routes (with admin token ability)
    Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
        Route::get('dashboard', [NyscAdminController::class, 'dashboard']);
        Route::get('dashboard-with-settings', [NyscAdminController::class, 'getDashboardWithSettings']);
        Route::post('control', [NyscAdminController::class, 'control']);
        Route::get('control', [NyscAdminController::class, 'getControl']);
        
        // Admin Settings Management
        Route::get('settings', [NyscAdminController::class, 'getSettings']);
        Route::put('settings', [NyscAdminController::class, 'updateSettings']);
        Route::get('students', [NyscAdminController::class, 'getStudents']);
        Route::get('students-data', [NyscAdminController::class, 'getStudentsData']);
        Route::put('student/{studentId}', [NyscAdminController::class, 'updateStudent']);
        Route::get('exports/{format}', [NyscAdminController::class, 'export']);
        Route::get('export-students/{format}', [NyscAdminController::class, 'exportStudents']);
        Route::get('payments', [NyscAdminController::class, 'payments']);
        Route::get('payments/statistics', [NyscAdminController::class, 'getPaymentStatistics']);
        Route::get('payments/statistics/export', [NyscAdminController::class, 'exportPaymentStatistics']);
        Route::post('payments/statistics/hide', [NyscAdminController::class, 'hideStudentsPayments']);
        Route::get('payments/{id}', [NyscAdminController::class, 'getPaymentDetails'])->whereNumber('id');
        Route::post('payments/{id}/verify', [NyscAdminController::class, 'verifyPayment'])->whereNumber('id');
        Route::post('payments/verify-all', [NyscAdminController::class, 'verifyAllPendingPayments']);
        
        // Submissions management routes
        Route::get('submissions', [NyscAdminController::class, 'getSubmissions']);
        Route::get('submissions/{submissionId}', [NyscAdminController::class, 'getSubmissionDetails']);
        Route::put('submissions/{submissionId}/status', [NyscAdminController::class, 'updateSubmissionStatus']);
        
        // Export jobs management routes
        Route::post('export-jobs', [NyscAdminController::class, 'createExportJob']);
        Route::get('export-jobs', [NyscAdminController::class, 'getExportJobs']);
        Route::get('export-jobs/{jobId}', [NyscAdminController::class, 'getExportJobStatus']);
        Route::get('export-jobs/{jobId}/download', [NyscAdminController::class, 'downloadExportFile']);
        
        // Student management routes
        Route::get('students/all', [NyscAdminController::class, 'getAllStudents']);
        Route::get('students/stats', [NyscAdminController::class, 'getStudentStats']);
        Route::get('students/{studentId}', [NyscAdminController::class, 'getStudentDetails']);
        Route::get('students/export', [NyscAdminController::class, 'exportStudents']);
        
        // Students list routes (new functionality)
        Route::get('students-list', [NyscAdminController::class, 'getStudentsList']);
        Route::get('students-list/export', [NyscAdminController::class, 'exportStudentsList']);
        
        // System settings routes
        Route::get('settings/system', [NyscAdminController::class, 'getSystemSettings']);
        Route::put('settings/system', [NyscAdminController::class, 'updateSystemSettings']);
        Route::get('settings/email', [NyscAdminController::class, 'getEmailSettings']);
        Route::put('settings/email', [NyscAdminController::class, 'updateEmailSettings']);
        Route::post('settings/test-email', [NyscAdminController::class, 'testEmail']);
        Route::post('settings/clear-cache', [NyscAdminController::class, 'clearCache']);
        
        // Admin user management routes
        Route::get('admin-users', [NyscAdminController::class, 'getAdminUsers']);
        Route::post('admin-users', [NyscAdminController::class, 'createAdminUser']);
        Route::put('admin-users/{userId}', [NyscAdminController::class, 'updateAdminUser']);
        Route::delete('admin-users/{userId}', [NyscAdminController::class, 'deleteAdminUser']);
        
        // Admin profile management
        Route::put('profile', [NyscAdminController::class, 'updateAdminProfile']);
        
        // Duplicate payments management
        Route::get('duplicate-payments', [NyscDuplicatePaymentController::class, 'getDuplicatePayments']);
        
        // CSV upload and additional settings routes
        Route::post('upload-csv', [NyscAdminController::class, 'uploadCsv']);
        Route::get('csv-template', [NyscAdminController::class, 'downloadCsvTemplate']);
        Route::post('clear-cache', [NyscAdminController::class, 'clearCache']);
        Route::post('test-email', [NyscAdminController::class, 'testEmail']);
        
        // Excel import functionality
        Route::get('excel-import/eligible-records', [\App\Http\Controllers\NyscAdminExcelController::class, 'getEligibleRecords']);
        Route::post('excel-import/import-selected', [\App\Http\Controllers\NyscAdminExcelController::class, 'importSelectedRecords']);
        Route::post('excel-import/import-all', [\App\Http\Controllers\NyscAdminExcelController::class, 'importAllEligibleRecords']);
        Route::post('excel-import/cleanup-duplicates', [\App\Http\Controllers\NyscAdminExcelController::class, 'cleanupDuplicateRecords']);
        
        // DOCX import functionality
        Route::get('docx-import/test', [NyscDocxImportController::class, 'test']);
        Route::post('docx-import/upload', [NyscDocxImportController::class, 'uploadDocx']);
        Route::get('docx-import/review/{sessionId}', [NyscDocxImportController::class, 'getReviewData']);
        Route::post('docx-import/approve', [NyscDocxImportController::class, 'approveUpdates']);
        Route::get('docx-import/stats', [NyscDocxImportController::class, 'getImportStats']);
        Route::get('docx-import/export-student-data', [NyscDocxImportController::class, 'exportStudentData']);
        Route::get('docx-import/export-null-students', function() {
            try {
                $students = \App\Models\StudentNysc::whereNull('class_of_degree')->limit(5)->get(['matric_no', 'fname', 'lname']);
                return response()->json(['success' => true, 'count' => $students->count(), 'sample' => $students]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 500);
            }
        });
        Route::get('docx-import/test-null-export', function() {
            try {
                $controller = new \App\Http\Controllers\NyscDocxImportController();
                $result = $controller->exportStudentsWithNullDegree();
                return response()->json(['status' => 'Method executed successfully', 'result_type' => get_class($result)]);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
            }
        })->withoutMiddleware(['auth:sanctum']);
        Route::get('docx-import/graduands-matches', [NyscDocxImportController::class, 'getGraduandsMatches']);
        Route::post('docx-import/graduands-apply', [NyscDocxImportController::class, 'applyGraduandsUpdates']);
        Route::get('docx-import/data-analysis', [NyscDocxImportController::class, 'getDataAnalysis']);
        Route::post('docx-import/test-db-update', [NyscAdminController::class, 'testDatabaseUpdate']);
        
        // CSV Export routes
        Route::get('csv-export/test', [NyscAdminController::class, 'testCsvExport']);
        Route::get('csv-export/student-data', [NyscAdminController::class, 'exportStudentNyscCsv']);
        Route::get('csv-export/stats', [NyscAdminController::class, 'getCsvExportStats']);
        
        // NYSC Upload Analysis routes
        Route::get('upload-analysis', [\App\Http\Controllers\NyscUploadAnalysisController::class, 'analyzeUploads']);
        Route::get('upload-analysis/export-unuploaded', [\App\Http\Controllers\NyscUploadAnalysisController::class, 'exportUnuploaded']);
        Route::get('upload-analysis/test-pdf', [\App\Http\Controllers\NyscUploadAnalysisController::class, 'testPdfFile']);
        
        // Payment Verification routes
        Route::get('payments/pending-stats', [\App\Http\Controllers\NyscAdminController::class, 'getPendingPaymentsStats']);
        Route::post('payments/verify-pending', [\App\Http\Controllers\NyscAdminController::class, 'verifyPendingPayments']);
        Route::post('payments/{payment}/verify', [\App\Http\Controllers\NyscAdminController::class, 'verifySinglePayment'])->whereNumber('payment');
        
        // Test endpoint for pending payments
        Route::get('payments/test', [\App\Http\Controllers\NyscAdminController::class, 'testPendingPayments']);
        
        // Temporary test endpoint without auth
        Route::get('payments/test-no-auth', [\App\Http\Controllers\NyscAdminController::class, 'getPendingPaymentsStats']);
        
        // Debug route (temporary)
        Route::get('payments/debug', [\App\Http\Controllers\NyscAdminController::class, 'debugPayments']);
    });
});

// Public export route for null degree students (no authentication required)
Route::get('nysc/export-null-degree-students', [App\Http\Controllers\NyscDocxImportController::class, 'exportStudentsWithNullDegree']);

// Test endpoint to verify frontend can reach backend
Route::get('nysc/test-connection', function() {
    return response()->json(['status' => 'Backend reachable', 'timestamp' => now()]);
});

// Data analysis endpoint
Route::get('nysc/data-analysis', [App\Http\Controllers\NyscDocxImportController::class, 'getDataAnalysis']);
