<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\StudentNysc;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\NyscExport;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use Symfony\Component\HttpFoundation\Response;

class NyscDataController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->filteredQuery($request)->get();

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }

    public function export(Request $request, $format)
    {
        $query = $this->filteredQuery($request);
        $fileName = 'nysc_data_' . now()->format('Ymd_His');

        switch (strtolower($format)) {
            case 'csv':
                return Excel::download(new NyscExport($query, true), $fileName . '.csv');
            case 'xlsx':
                // Store all cells as text so leading zeros in phone numbers,
                // dates and years are preserved when opened in Excel.
                Cell::setValueBinder(new StringValueBinder());
                return Excel::download(new NyscExport($query), $fileName . '.xlsx');
            case 'pdf':
                $data = $query->get();
                // Temporary workaround: return HTML view for PDF printing
                return view('exports.nysc_pdf', ['data' => $data])
                    ->header('Content-Type', 'text/html')
                    ->header('Content-Disposition', 'inline; filename="' . $fileName . '.html"');
            default:
                return response()->json(['error' => 'Invalid format'], Response::HTTP_BAD_REQUEST);
        }
    }

    private function filteredQuery(Request $request)
    {
        $query = StudentNysc::query();

        $sessionId = $request->input('session_id') ?: AdminSetting::get('active_session_id');
        if ($sessionId) {
            $query->where('nysc_session_id', $sessionId);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('updated_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('updated_at', '<=', $request->input('date_to'));
        }

        return $query;
    }
}
