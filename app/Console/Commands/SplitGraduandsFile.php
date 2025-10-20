<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DocxImportService;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class SplitGraduandsFile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nysc:split-graduands {--parts=4 : Number of parts to split into} {--method=count : Split method (count or alpha)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Split GRADUANDS.docx file into smaller parts for better processing';

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
        $this->info('Starting GRADUANDS.docx file splitting...');
        
        $filePath = storage_path('app/GRADUANDS.docx');
        $parts = (int) $this->option('parts');
        $method = $this->option('method');
        
        // Check if file exists
        if (!file_exists($filePath)) {
            $this->error('GRADUANDS.docx file not found at: ' . $filePath);
            return 1;
        }

        try {
            $this->info('Processing file: ' . $filePath);
            $this->info('File size: ' . $this->formatBytes(filesize($filePath)));
            $this->info('Split method: ' . $method);
            $this->info('Number of parts: ' . $parts);

            // Extract data from the original file
            $result = $this->docxImportService->processDocxFile($filePath);
            
            if (!$result['success']) {
                $this->error('Failed to process GRADUANDS.docx: ' . $result['error']);
                return 1;
            }

            $allData = $result['review_data'];
            $totalRecords = count($allData);
            
            $this->info("Total records found: {$totalRecords}");

            if ($totalRecords === 0) {
                $this->error('No records found in the file');
                return 1;
            }

            // Split the data
            $splitData = $this->splitData($allData, $parts, $method);
            
            // Create new DOCX files
            $this->createSplitFiles($splitData, $parts);
            
            $this->info('File splitting completed successfully!');
            $this->info('Created files:');
            
            for ($i = 1; $i <= $parts; $i++) {
                $fileName = $i === 1 ? 'GRADUANDS.docx' : "GRADUANDS_Part{$i}.docx";
                $filePath = storage_path('app/' . $fileName);
                if (file_exists($filePath)) {
                    $this->info("  - {$fileName} (" . count($splitData[$i-1]) . " records, " . $this->formatBytes(filesize($filePath)) . ")");
                }
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('Error splitting file: ' . $e->getMessage());
            Log::error('GRADUANDS file splitting error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Split data into parts
     */
    private function splitData(array $data, int $parts, string $method): array
    {
        $totalRecords = count($data);
        $splitData = array_fill(0, $parts, []);

        if ($method === 'alpha') {
            // Sort by matric number for alphabetical splitting
            usort($data, function($a, $b) {
                return strcmp($a['matric_no'], $b['matric_no']);
            });
        }

        // Distribute records evenly
        $recordsPerPart = ceil($totalRecords / $parts);
        
        for ($i = 0; $i < $totalRecords; $i++) {
            $partIndex = intval($i / $recordsPerPart);
            if ($partIndex >= $parts) {
                $partIndex = $parts - 1; // Put remaining in last part
            }
            $splitData[$partIndex][] = $data[$i];
        }

        return $splitData;
    }

    /**
     * Create split DOCX files
     */
    private function createSplitFiles(array $splitData, int $parts): void
    {
        for ($i = 0; $i < $parts; $i++) {
            $partData = $splitData[$i];
            
            if (empty($partData)) {
                continue;
            }

            // Create filename
            $fileName = $i === 0 ? 'GRADUANDS.docx' : "GRADUANDS_Part" . ($i + 1) . ".docx";
            $filePath = storage_path('app/' . $fileName);
            
            // Create new DOCX document
            $phpWord = new PhpWord();
            $section = $phpWord->addSection();
            
            // Add title
            $section->addText("GRADUANDS - Part " . ($i + 1), ['bold' => true, 'size' => 16]);
            $section->addTextBreak();
            
            // Create table
            $table = $section->addTable([
                'borderSize' => 6,
                'borderColor' => '000000',
                'cellMargin' => 80
            ]);
            
            // Add header row
            $table->addRow();
            $table->addCell(3000)->addText('MATRIC NO', ['bold' => true]);
            $table->addCell(4000)->addText('CLASS OF DEGREE', ['bold' => true]);
            
            // Add data rows
            foreach ($partData as $record) {
                $table->addRow();
                $table->addCell(3000)->addText($record['matric_no']);
                $table->addCell(4000)->addText($record['proposed_class_of_degree']);
            }
            
            // Save the document
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($filePath);
            
            $this->info("Created: {$fileName} with " . count($partData) . " records");
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