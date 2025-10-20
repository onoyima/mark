<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class NyscDocxImportControllerSimple extends Controller
{
    /**
     * Simple test upload method
     */
    public function uploadDocx(Request $request): JsonResponse
    {
        try {
            Log::info('Simple DOCX upload test');
            
            if (!$request->hasFile('docx_file')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No file uploaded'
                ], 422);
            }

            $file = $request->file('docx_file');
            
            return response()->json([
                'success' => true,
                'message' => 'File received successfully',
                'session_id' => 'test-session-123',
                'summary' => [
                    'total_extracted' => 5,
                    'total_matched' => 3,
                    'ready_for_review' => 3
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Simple upload error', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
}