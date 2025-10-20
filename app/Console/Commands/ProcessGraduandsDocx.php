<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DocxImportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProcessGraduandsDocx extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nysc:process-graduands {--force : Force processing even if file hasn\'t changed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process GRADUANDS.docx file and extract class of degree information';

    protected $docxImportService;

    public function __construct(DocxImportService $docxImportService)
    {
        parent::__construct();
        $this->docxImportService = $docxImportService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting GRADUANDS.docx processing...');
        
        $filePath = storage_path('app/GRADUANDS.docx');
        
        // Check if file exists
        if (!file_exists($filePath)) {
            $this->error('GRADUANDS.docx file not found at: ' . $filePath);
            Log::error('GRADUANDS.docx file not found', ['path' => $filePath]);
            return 1;
        }

        // Check file modification time to avoid reprocessing unchanged files
        $fileModTime = filemtime($filePath);
        $lastProcessedTime = Cache::get('graduands_last_processed', 0);
        
        if (!$this->option('force') && $fileModTime <= $lastProcessedTime) {
            $this->info('File has not changed since last processing. Use --force to process anyway.');
            return 0;
        }

        try {
            $this->info('Processing file: ' . $filePath);
            $this->info('File size: ' . $this->formatBytes(filesize($filePath)));
            $this->info('Last modified: ' . date('Y-m-d H:i:s', $fileModTime));

            // Process the DOCX file
            $result = $this->docxImportService->processDocxFile($filePath);
            
            if (!$result['success']) {
                $this->error('Processing failed: ' . $result['error']);
                Log::error('GRADUANDS.docx processing failed', ['error' => $result['error']]);
                return 1;
            }

            // Store the processed data in cache for admin review
            $sessionId = 'graduands_' . date('Y_m_d_H_i_s');
            $sessionData = [
                'session_id' => $sessionId,
                'file_path' => $filePath,
                'original_filename' => 'GRADUANDS.docx',
                'review_data' => $result['review_data'],
                'summary' => $result['summary'],
                'created_at' => now(),
                'expires_at' => now()->addDays(7), // Keep for 7 days
                'auto_processed' => true
            ];
            
            Cache::put("graduands_session_{$sessionId}", $sessionData, now()->addDays(7));
            Cache::put('graduands_latest_session', $sessionId, now()->addDays(7));
            Cache::put('graduands_last_processed', $fileModTime);

            $this->info('Processing completed successfully!');
            $this->info('Summary:');
            $this->info('  - Total extracted: ' . $result['summary']['total_extracted']);
            $this->info('  - Total matched: ' . $result['summary']['total_matched']);
            $this->info('  - Ready for review: ' . $result['summary']['ready_for_review']);
            $this->info('Session ID: ' . $sessionId);
            
            Log::info('GRADUANDS.docx processed successfully', [
                'session_id' => $sessionId,
                'summary' => $result['summary']
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error('Error processing file: ' . $e->getMessage());
            Log::error('GRADUANDS.docx processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}