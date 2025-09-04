<?php

namespace App\Http\Controllers;

use App\Models\StudentNysc;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\NyscExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class NyscDataController extends Controller
{
    public function index()
    {
        $data = StudentNysc::all();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }

    public function export($format)
    {
        $fileName = 'nysc_data_' . now()->format('Ymd_His');

        switch (strtolower($format)) {
            case 'csv':
                return Excel::download(new NyscExport, $fileName . '.csv');
            case 'xlsx':
                return Excel::download(new NyscExport, $fileName . '.xlsx');
            case 'pdf':
                $data = StudentNysc::all();
                // Temporary workaround: return HTML view for PDF printing
                return view('exports.nysc_pdf', ['data' => $data])
                    ->header('Content-Type', 'text/html')
                    ->header('Content-Disposition', 'inline; filename="' . $fileName . '.html"');
            default:
                return response()->json(['error' => 'Invalid format'], Response::HTTP_BAD_REQUEST);
        }
    }
}
