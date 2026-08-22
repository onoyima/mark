<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Student;
use App\Models\StudentNysc;
use App\Models\Staff;
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
        // No longer anonymous: guests are rejected, students only ever
        // receive their own record, staff/admins see the full list.
        $user = $request->user('sanctum');

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication required. Please log in to view your details.'
            ], 401);
        }

        if ($user instanceof Student) {
            // A student sees only their own row(s), regardless of which
            // session is active so their record never "disappears".
            $data = StudentNysc::where('student_id', $user->id)->get();
        } else {
            $data = $this->filteredQuery($request)->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $data
        ]);
    }

    public function export(Request $request, $format)
    {
        // Exports contain unmasked PII (DOB, phone, etc.) and are therefore
        // restricted to authenticated staff/admin accounts.
        $user = $request->user('sanctum');
        if (!$user instanceof Staff) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Only administrators can export data.'
            ], 403);
        }

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
