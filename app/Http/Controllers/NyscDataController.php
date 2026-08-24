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

        // Exports are strictly filter-driven: whatever the admin selected
        // (specific session or ALL sessions) is exactly what gets exported.
        // No silent fallback to the active session here.
        $query = $this->filteredQuery($request, false);
        $fileName = 'nysc_data_' . now()->format('Ymd_His');

        switch (strtolower($format)) {
            case 'csv':
                // Same streaming CSV shape as the /admin/csv-export page so
                // both download buttons produce identical files.
                $students = $query->select([
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
                    'study_mode',
                    'is_military',
                    'is_status'
                ])->get();

                return $this->streamTableCsv($students, 'student_nysc_data_' . now()->format('Y-m-d_H-i-s') . '.csv');
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

    /**
     * Stream a CSV in the exact format produced by the /admin/csv-export
     * page: UTF-8 BOM, table column names, gender as M/F, status as
     * page: UTF-8 BOM, table column names, gender as M/F, status always
     * Fresh, military as Yes/No, and apostrophe protection on
     * phone, dob and graduation_year so Excel keeps leading zeros.
     */
    private function streamTableCsv($students, string $filename)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
            'Pragma' => 'public',
        ];

        // Columns that must keep leading zeros / exact text formatting:
        // 4 = phone, 7 = dob, 8 = graduation_year
        // matric_no (0) and jamb_no (11) are intentionally excluded.
        $textColumns = [4, 7, 8];

        $genderMap = ['male' => 'M', 'female' => 'F'];

        $callback = function () use ($students, $textColumns, $genderMap) {
            $file = fopen('php://output', 'w');

            // Add BOM for proper UTF-8 encoding in Excel
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

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
                'status',
                'gender',
                'marital_status',
                'jamb_no',
                'is_military',
                'course_study',
                'study_mode'
            ]);

            foreach ($students as $student) {
                $genderKey = strtolower(trim((string) $student->gender));

                $row = [
                    $student->matric_no,
                    $student->fname,
                    $student->mname,
                    $student->lname,
                    $student->phone,
                    $student->state,
                    $student->class_of_degree,
                    $student->dob ? $student->dob->format('d/m/Y') : '',
                    $student->graduation_year,
                    'Fresh',
                    $genderMap[$genderKey] ?? $student->gender,
                    $student->marital_status,
                    $student->jamb_no,
                    $student->is_military ? 'Yes' : 'No',
                    $student->course_study,
                    $student->study_mode
                ];

                foreach ($textColumns as $i) {
                    if (isset($row[$i]) && $row[$i] !== '' && $row[$i] !== null) {
                        $row[$i] = '\'' . $row[$i];
                    }
                }

                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Build the student query honouring the admin's explicit filter choices.
     *
     * When the request contains a session_id key at all, that value is
     * authoritative: a specific id filters to that session, an empty value
     * means "All Sessions". Only when the key is absent entirely do we fall
     * back to the active session (and only if $defaultToActiveSession).
     */
    private function filteredQuery(Request $request, bool $defaultToActiveSession = true)
    {
        $query = StudentNysc::query();

        $sessionId = $request->exists('session_id')
            ? $request->input('session_id')
            : ($defaultToActiveSession ? AdminSetting::get('active_session_id') : null);

        if ($sessionId !== null && $sessionId !== '') {
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
