<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Services\FileProcessingService;

class ProcessFileImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $originalName;
    protected $sessionId;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $filePath, string $originalName, string $sessionId, int $userId)
    {
        $this->filePath = $filePath;
        $this->originalName = $originalName;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(FileProcessingService $fileProcessingService): void
    {
        try {
            Log::info('Starting file import job', [
                'session_id' => $this->sessionId,
                'file_path' => $this->filePath,
                'original_name' => $this->originalName,
                'user_id' => $this->userId
            ]);

            // Update job status to processing
            Cache::put("file_import_status_{$this->sessionId}", [
                'status' => 'processing',
                'progress' => 10,
                'message' => 'Processing file...',
                'started_at' => now()
            ], now()->addHours(2));

            // Process the file
            $result = $fileProcessingService->processFile($this->filePath, $this->originalName);

            if ($result['success']) {
                // Store the results in cache
                $sessionData = [
                    'session_id' => $this->sessionId,
                    'file_path' => $this->filePath,
                    'original_filename' => $this->originalName,
                    'review_data' => $result['review_data'],
                    'summary' => $result['summary'],
                    'file_type' => $result['file_type'],
                    'user_id' => $this->userId,
                    'created_at' => now(),
                    'expires_at' => now()->addHours(6)
                ];

                Cache::put("file_import_session_{$this->sessionId}", $sessionData, now()->addHours(6));

                // Update job status to completed
                Cache::put("file_import_status_{$this->sessionId}", [
                    'status' => 'completed',
                    'progress' => 100,
                    'message' => 'File processed successfully',
                    'completed_at' => now(),
                    'summary' => $result['summary']
                ], now()->addHours(2));

                Log::info('File import job completed successfully', [
                    'session_id' => $this->sessionId,
                    'summary' => $result['summary']
                ]);
            } else {
                throw new \Exception($result['error']);
            }

        } catch (\Exception $e) {
            Log::error('File import job failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update job status to failed
            Cache::put("file_import_status_{$this->sessionId}", [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'File processing failed: ' . $e->getMessage(),
                'failed_at' => now(),
                'error' => $e->getMessage()
            ], now()->addHours(2));

            // Clean up the uploaded file
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('File import job failed permanently', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage()
        ]);

        // Update job status to failed
        Cache::put("file_import_status_{$this->sessionId}", [
            'status' => 'failed',
            'progress' => 0,
            'message' => 'File processing failed permanently: ' . $exception->getMessage(),
            'failed_at' => now(),
            'error' => $exception->getMessage()
        ], now()->addHours(2));

        // Clean up the uploaded file
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }
}