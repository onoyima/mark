<?php

namespace App\Http\Controllers;

use App\Models\Studentnysc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class NyscDocumentController extends Controller
{
    /**
     * Get all documents for authenticated student
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDocuments(): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();
        
        // Get NYSC record
        $nysc = Studentnysc::where('student_id', $student->id)->first();
        
        if (!$nysc) {
            return response()->json([
                'documents' => [],
            ]);
        }
        
        // Get documents from storage
        $documentsPath = 'nysc/documents/' . $student->id;
        $files = Storage::disk('public')->files($documentsPath);
        
        $documents = [];
        foreach ($files as $file) {
            $filename = basename($file);
            $documents[] = [
                'id' => Str::uuid(),
                'name' => $filename,
                'url' => Storage::disk('public')->url($file),
                'size' => Storage::disk('public')->size($file),
                'uploaded_at' => date('Y-m-d H:i:s', Storage::disk('public')->lastModified($file)),
            ];
        }
        
        return response()->json([
            'documents' => $documents,
        ]);
    }
    
    /**
     * Upload a document for authenticated student
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadDocument(Request $request): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();
        
        // Validate the request
        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB max
            'type' => 'required|string|in:passport,certificate,transcript,other',
        ]);
        
        // Get or create NYSC record
        $nysc = Studentnysc::firstOrCreate(
            ['student_id' => $student->id],
            ['is_submitted' => false, 'is_paid' => false]
        );
        
        try {
            $file = $request->file('document');
            $type = $request->input('type');
            
            // Generate unique filename
            $filename = $type . '_' . time() . '_' . Str::random(8) . '.' . $file->getClientOriginalExtension();
            
            // Store the file
            $path = $file->storeAs('nysc/documents/' . $student->id, $filename, 'public');
            
            return response()->json([
                'message' => 'Document uploaded successfully.',
                'document' => [
                    'id' => Str::uuid(),
                    'name' => $filename,
                    'type' => $type,
                    'url' => Storage::disk('public')->url($path),
                    'size' => $file->getSize(),
                    'uploaded_at' => now(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Delete a document for authenticated student
     *
     * @param  string  $filename
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteDocument($filename): \Illuminate\Http\JsonResponse
    {
        $student = Auth::user();
        
        try {
            $filePath = 'nysc/documents/' . $student->id . '/' . $filename;
            
            if (!Storage::disk('public')->exists($filePath)) {
                return response()->json([
                    'message' => 'Document not found.',
                ], 404);
            }
            
            Storage::disk('public')->delete($filePath);
            
            return response()->json([
                'message' => 'Document deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete document.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}