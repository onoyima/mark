<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\StudentNysc;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class NyscPreparedListController extends Controller
{
    /**
     * Storage directory (relative to the local disk root) where uploaded
     * "prepared" Excel lists live. Files are NEVER imported into any table —
     * they are only compared against student_nysc by matric number.
     */
    private string $storageDir = 'prepared-lists';

    /**
     * Fields cross-checked between the prepared list and student_nysc to
     * detect students who updated their details AFTER the list was made.
     */
    private array $changeFields = ['fname', 'mname', 'lname', 'dob', 'gender', 'marital_status', 'jamb_no'];

    /**
     * Canonical column keys we recognise in an uploaded list, mapped from
     * every header spelling we are willing to accept. Comparison uses
     * normalised headers so "MatricNo", "matric_no", "MATRIC _NO" all work.
     */
    private array $headerAliases = [
        'matricno' => 'matric_no', 'matric' => 'matric_no', 'matricnumber' => 'matric_no', 'matricnum' => 'matric_no',
        'firstname' => 'fname', 'fname' => 'fname',
        'middlename' => 'mname', 'mname' => 'mname',
        'surname' => 'lname', 'lastname' => 'lname', 'lname' => 'lname',
        'gsmno' => 'phone', 'gsm' => 'phone', 'phonenumber' => 'phone', 'phone' => 'phone',
        'stateoforigin' => 'state', 'state' => 'state',
        'classofdegree' => 'class_of_degree',
        'dateofbirth' => 'dob', 'dob' => 'dob',
        'dateofgraduation' => 'graduation_year', 'yearofgraduation' => 'graduation_year', 'graduationyear' => 'graduation_year',
        'status' => 'status', 'isstatus' => 'status',
        'gender' => 'gender', 'sex' => 'gender',
        'maritalstatus' => 'marital_status',
        'jambregno' => 'jamb_no', 'jambno' => 'jamb_no', 'jambregistrationnumber' => 'jamb_no', 'jamb' => 'jamb_no',
        'ismilitary' => 'is_military', 'military' => 'is_military',
        'courseofstudy' => 'course_study', 'coursestudy' => 'course_study', 'course' => 'course_study',
        'studymode' => 'study_mode',
    ];

    // ---------------------------------------------------------------------
    // File management
    // ---------------------------------------------------------------------

    public function index()
    {
        try {
            $files = [];
            foreach (Storage::disk('local')->files($this->storageDir) as $path) {
                $files[] = [
                    'filename'          => basename($path),
                    'size_bytes'        => Storage::disk('local')->size($path),
                    'size_human'        => $this->humanSize(Storage::disk('local')->size($path)),
                    'uploaded_at'       => optional(\Carbon\Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path)))->toIso8601String(),
                ];
            }

            usort($files, fn ($a, $b) => strcmp($b['uploaded_at'], $a['uploaded_at']));

            return response()->json(['success' => true, 'files' => $files]);
        } catch (\Exception $e) {
            Log::error('Prepared list listing failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to list prepared lists'], 500);
        }
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only .xlsx, .xls or .csv files are accepted.'
            ], 422);
        }

        // Sanitise: keep a readable name but strip anything risky.
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9._\- ]+/', '_', $base)) ?: 'prepared_list';
        $filename = $base . '.' . $ext;

        // Avoid silent overwrites of previous lists.
        if (Storage::disk('local')->exists($this->storageDir . '/' . $filename)) {
            $filename = $base . '_' . now()->format('Ymd_His') . '.' . $ext;
        }

        Storage::disk('local')->putFileAs($this->storageDir, $file, $filename);

        Log::info('Prepared list uploaded', ['filename' => $filename]);

        return response()->json([
            'success' => true,
            'message' => 'Prepared list uploaded successfully.',
            'file'    => ['filename' => $filename],
        ]);
    }

    public function destroy(Request $request, $filename)
    {
        $path = $this->resolvePath($filename);
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        Storage::disk('local')->delete($path);

        return response()->json(['success' => true, 'message' => 'Deleted']);
    }

    // ---------------------------------------------------------------------
    // Comparison
    // ---------------------------------------------------------------------

    public function compare(Request $request)
    {
        try {
            [$list, $error] = $this->loadList($request);
            if ($error !== null) {
                return response()->json(['success' => false, 'message' => $error], 404);
            }

            $portalRows = $this->portalQuery($request)
                ->get([
                    'matric_no', 'fname', 'mname', 'lname', 'phone', 'state',
                    'class_of_degree', 'dob', 'graduation_year', 'gender',
                    'marital_status', 'jamb_no', 'is_military', 'course_study',
                    'study_mode', 'nysc_session_id', 'updated_at',
                ]);

            // Index portal rows by normalised matric.
            $portalMap = [];
            foreach ($portalRows as $row) {
                $key = $this->normalizeMatric($row->matric_no);
                if ($key !== '') {
                    $portalMap[$key] = $row;
                }
            }

            $notPrepared = [];
            foreach ($portalRows as $row) {
                $key = $this->normalizeMatric($row->matric_no);
                if (!isset($list['keys'][$key])) {
                    $notPrepared[] = $row;
                }
            }

            $inListNotOnPortal = [];
            foreach ($list['rows'] as $row) {
                $key = $this->normalizeMatric($row['_matric'] ?? null);
                if ($key !== '' && !isset($portalMap[$key])) {
                    $inListNotOnPortal[] = $row;
                }
            }

            $preparedCount = 0;
            foreach (array_keys($list['keys']) as $k) {
                if (isset($portalMap[$k])) {
                    $preparedCount++;
                }
            }

            // Cross-check tracked fields: students whose portal data now
            // differs from what the prepared list recorded.
            $changedStudents = [];
            foreach ($portalRows as $row) {
                $key = $this->normalizeMatric($row->matric_no);
                $listRow = $list['by_key'][$key] ?? null;
                if ($listRow === null) {
                    continue;
                }

                $diffs = $this->diffForStudent($row, $listRow);
                if (!empty($diffs)) {
                    $changedStudents[] = [
                        'matric_no'  => $row->matric_no,
                        'full_name'  => trim(($row->fname ?? '') . ' ' . ($row->mname ?? '') . ' ' . ($row->lname ?? '')),
                        'session_id' => $row->nysc_session_id,
                        'updated_at' => optional($row->updated_at)->format('d/m/Y H:i'),
                        'changes'    => $diffs,
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_in_list'          => count($list['rows']),
                    'unique_in_list'         => count($list['keys']),
                    'duplicate_matrics'      => $list['duplicates'],
                    'total_portal_scoped'    => $portalRows->count(),
                    'prepared_on_portal'     => $preparedCount,
                    'coverage_percentage'    => count($list['keys']) > 0
                        ? round(($preparedCount / count($list['keys'])) * 100, 2)
                        : 0,
                    'not_prepared_count'     => count($notPrepared),
                    'in_list_not_on_portal'  => count($inListNotOnPortal),
                    'changed_on_portal'      => count($changedStudents),
                ],
                'changed_preview' => array_slice($changedStudents, 0, 100),
                'not_prepared_preview' => array_slice(array_map(fn ($r) => [
                    'matric_no'       => $r->matric_no,
                    'full_name'       => trim(($r->fname ?? '') . ' ' . ($r->mname ?? '') . ' ' . ($r->lname ?? '')),
                    'class_of_degree' => $r->class_of_degree,
                ], $notPrepared), 0, 100),
                'missing_on_portal_preview' => array_slice(array_map(fn ($r) => [
                    'matric_no' => $r['_matric'],
                    'name'      => trim(($r['fname'] ?? $r['FirstName'] ?? '') . ' ' . ($r['lname'] ?? $r['Surname'] ?? '')),
                ], $inListNotOnPortal), 0, 100),
            ]);
        } catch (\Exception $e) {
            Log::error('Prepared list comparison failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Comparison failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // Exports
    // ---------------------------------------------------------------------

    /**
     * Download everyone on the portal (scoped to session) whose matric is NOT
     * in the selected prepared list — the "still to prepare" gap. Standard
     * 16-column portal format; class_of_degree included whether set or NULL.
     */
    public function exportNotPrepared(Request $request)
    {
        try {
            [$list, $error] = $this->loadList($request);
            if ($error !== null) {
                return response()->json(['success' => false, 'message' => $error], 404);
            }

            $rows = $this->portalQuery($request)->get();

            $genderMap = ['male' => 'M', 'female' => 'F'];
            $out = [];

            foreach ($rows as $s) {
                if (isset($list['keys'][$this->normalizeMatric($s->matric_no)])) {
                    continue; // already prepared
                }

                $genderKey = strtolower(trim((string) $s->gender));

                $out[] = [
                    $s->matric_no,
                    $s->fname,
                    $s->mname,
                    $s->lname,
                    $s->phone,
                    $s->state,
                    $s->class_of_degree,
                    $s->dob ? $s->dob->format('d/m/Y') : '',
                    $s->graduation_year,
                    'Fresh',
                    $genderMap[$genderKey] ?? $s->gender,
                    $s->marital_status,
                    $s->jamb_no,
                    $s->is_military ? 'Yes' : 'No',
                    $s->course_study,
                    $s->study_mode,
                ];
            }

            $headers = [
                'matric_no', 'fname', 'mname', 'lname', 'phone', 'state',
                'class_of_degree', 'dob', 'graduation_year', 'status',
                'gender', 'marital_status', 'jamb_no', 'is_military',
                'course_study', 'study_mode',
            ];

            return $this->csvResponse(
                'not_prepared_' . now()->format('Y-m-d_H-i-s') . '.csv',
                $headers,
                $out,
                [4, 7, 8] // phone(4), dob(7), graduation_year(8) keep leading zeros
            );
        } catch (\Exception $e) {
            Log::error('Not-prepared export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Download the prepared list itself — rows exactly as they appeared in
     * the uploaded file (original headers and values).
     */
    public function exportPrepared(Request $request)
    {
        try {
            [$list, $error] = $this->loadList($request);
            if ($error !== null) {
                return response()->json(['success' => false, 'message' => $error], 404);
            }

            $headers = $list['original_headers'];
            $out = array_map(fn ($row) => $row['_raw'], $list['rows']);

            return $this->csvResponse(
                'prepared_list_' . now()->format('Y-m-d_H-i-s') . '.csv',
                $headers,
                $out,
                []
            );
        } catch (\Exception $e) {
            Log::error('Prepared export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------------
    // Change detection (portal vs prepared list)
    // ---------------------------------------------------------------------

    /**
     * Compare one portal row against its prepared-list row for the tracked
     * fields. Returns [field => ['old' => listValue, 'new' => portalValue]]
     * for every field that differs. Pure function — safe to unit test.
     */
    private function diffForStudent($portalRow, array $listRow): array
    {
        $diffs = [];

        foreach ($this->changeFields as $field) {
            $new = $field === 'dob'
                ? (string) optional($portalRow->dob)->format('Y-m-d')
                : trim((string) ($portalRow->$field ?? ''));

            $old = trim((string) ($listRow[$field] ?? ''));

            // The prepared list never recorded this field, so there is no
            // prior value to compare against. Skip instead of flagging every
            // such student as "updated" (blank columns must not create noise).
            if ($old === '') {
                continue;
            }

            if ($this->valuesMatch($field, $new, $old)) {
                continue;
            }

            $displayNew = $field === 'dob'
                ? (optional($portalRow->dob)->format('d/m/Y') ?? '')
                : $new;

            $diffs[$field] = ['old' => $old, 'new' => $displayNew];
        }

        return $diffs;
    }

    /**
     * Field-aware equality: dates are compared as real calendar dates
     * regardless of display format, gender tolerates M/Male/F/Female,
     * JAMB numbers ignore case and separators, everything else is a
     * whitespace-collapsed, case-insensitive text match.
     */
    private function valuesMatch(string $field, string $portalVal, string $listVal): bool
    {
        if ($portalVal === '' && $listVal === '') {
            return true;
        }

        switch ($field) {
            case 'dob':
                $p = $this->parseDateFlexible($portalVal);
                $l = $this->parseDateFlexible($listVal);
                if ($p !== null || $l !== null) {
                    return $p !== null && $l !== null && $p === $l;
                }
                return false; // at least one value present but unparseable

            case 'gender':
                return $this->normalizeGender($portalVal) !== ''
                    && $this->normalizeGender($portalVal) === $this->normalizeGender($listVal);

            case 'jamb_no':
                $simplify = fn ($v) => preg_replace('/[^A-Za-z0-9]/', '', strtoupper($v));
                return $simplify($portalVal) === $simplify($listVal);

            default:
                $collapse = fn ($v) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)));
                return $collapse($portalVal) === $collapse($listVal);
        }
    }

    private function normalizeGender(?string $value): string
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return '';
        }
        if ($v[0] === 'f' || str_contains($v, 'female')) {
            return 'F';
        }
        if ($v[0] === 'm' || str_contains($v, 'male')) {
            return 'M';
        }
        return '';
    }

    /**
     * Parse a date from any of the formats we realistically meet into
     * Y-m-d, or null when unparseable. Day-first formats are tried first
     * because that is this portal's convention.
     */
    private function parseDateFlexible(?string $value): ?string
    {
        // Tolerate stray Excel text-marker apostrophes glued to the value.
        $value = ltrim(trim((string) $value), "'");
        if ($value === '') {
            return null;
        }

        $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'j M Y', 'j F Y', 'jS M Y', 'jS F Y', 'j-M-Y', 'd-M-Y', 'M j, Y', 'F j, Y'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $value);

            // createFromFormat silently tolerates trailing junk and rollover
            // dates (31/02/2020 becomes March); only accept clean parses.
            $lastErrors = \DateTime::getLastErrors();
            $clean = $lastErrors === false
                || ($lastErrors['warning_count'] === 0 && $lastErrors['error_count'] === 0);

            if ($date instanceof \DateTime && $clean) {
                return $date->format('Y-m-d');
            }
        }

        // Raw Excel serial date that slipped through as text/number.
        if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 80000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((int) $value)->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Load + parse the requested prepared list. Returns [parsedArray|null, errorMessage|null].
     *
     * parsedArray shape:
     *  - original_headers: string[]   (verbatim first header row)
     *  - rows:             array      (each: canonical fields, _matric, _raw verbatim cells)
     *  - keys:             array      (normalised matric => true, for lookups)
     *  - duplicates:       int        (repeated matrics inside the list)
     */
    private function loadList(Request $request): array
    {
        $filename = (string) $request->input('file', '');
        $path = $this->resolvePath($filename);

        if (!$path || !Storage::disk('local')->exists($path)) {
            return [null, "Prepared list '{$filename}' not found. Upload one first."];
        }

        $spreadsheet = IOFactory::load(Storage::disk('local')->path($path));
        $sheet = $spreadsheet->getActiveSheet();
        $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $highestRow = $sheet->getHighestDataRow();

        // Locate the header row within the first 10 rows: it is the first row
        // containing something that normalises to matric_no.
        $headerRowIdx = null;
        $canonicalByCol = [];
        for ($r = 1; $r <= min(10, $highestRow); $r++) {
            $map = [];
            for ($c = 1; $c <= $highestCol; $c++) {
                $val = (string) $sheet->getCell([$c, $r])->getValue();
                $canon = $this->normalizeHeader($val);
                $map[$c] = $canon;
            }
            if (in_array('matric_no', $map, true)) {
                $headerRowIdx = $r;
                $canonicalByCol = $map;
                break;
            }
        }

        if ($headerRowIdx === null) {
            return [null, 'Could not find a MatricNo column in the uploaded file.'];
        }

        // Verbatim header labels for the "prepared" export.
        $originalHeaders = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $originalHeaders[] = (string) $sheet->getCell([$c, $headerRowIdx])->getFormattedValue();
        }

            $rows = [];
            $keys = [];
            $byKey = [];
            $duplicates = 0;

        for ($r = $headerRowIdx + 1; $r <= $highestRow; $r++) {
            $raw = [];
            $assoc = [];
            $matric = null;

            for ($c = 1; $c <= $highestCol; $c++) {
                $value = $this->cleanCell($sheet->getCell([$c, $r])->getFormattedValue());
                $raw[] = $value;

                $canon = $canonicalByCol[$c] ?? null;
                if ($canon === 'matric_no') {
                    $matric = $value;
                }
                if ($canon !== null) {
                    $assoc[$canon] = $value;
                }
            }

            // Skip blank rows AND section-label rows (e.g. a lone
            // "ENGLISH EDUCATION" divider): real student rows fill several
            // columns, labels fill one or two.
            $filled = count(array_filter($raw, fn ($v) => $v !== ''));
            if ($this->normalizeMatric($matric) === '' || $filled < 3) {
                continue;
            }

            $normKey = $this->normalizeMatric($matric);
            if (isset($keys[$normKey])) {
                $duplicates++;
            }
            $keys[$normKey] = true;
            if (!isset($byKey[$normKey])) {
                $byKey[$normKey] = $assoc; // first occurrence wins
            }

            $assoc['_matric'] = $matric;
            $assoc['_raw'] = $raw;
            $rows[] = $assoc;
        }

        $spreadsheet->disconnectWorksheets();

        return [[
            'original_headers' => $originalHeaders,
            'rows'             => $rows,
            'keys'             => $keys,
            'by_key'           => $byKey,
            'duplicates'       => $duplicates,
        ], null];
    }

    /**
     * Portal query with the standard explicit-wins session rule:
     * session_id present (even empty) wins; '' = ALL sessions; absent falls
     * back to the active session.
     *
     * Optional updated_from / updated_to (Y-m-d) narrow results to records
     * touched inside that window. Combined with '' session_id this finds
     * updates across ALL sessions — e.g. everything edited since the
     * prepared list was produced, wherever the student's record now sits.
     */
    private function portalQuery(Request $request)
    {
        $query = StudentNysc::query();

        $sessionId = $request->exists('session_id')
            ? $request->input('session_id')
            : AdminSetting::get('active_session_id');

        if ($sessionId !== null && $sessionId !== '') {
            $query->where('nysc_session_id', $sessionId);
        }

        return $this->applyUpdatedWindow($query, $request);
    }

    /**
     * Apply the optional updated_at date window. Invalid/empty values are
     * ignored so the caller never gets a broken query. Pure query-builder
     * work — nothing executes until ->get().
     */
    private function applyUpdatedWindow($query, Request $request)
    {
        foreach (['updated_from' => '>=', 'updated_to' => '<='] as $param => $operator) {
            $value = trim((string) $request->input($param, ''));
            if ($value === '') {
                continue;
            }

            try {
                $date = \Carbon\Carbon::createFromFormat('Y-m-d', $value);
                if ($date === false) {
                    continue;
                }
                $query->whereDate('updated_at', $operator, $date->toDateString());
            } catch (\Exception $e) {
                // Unparseable date — ignore the filter entirely.
            }
        }

        return $query;
    }

    /**
     * Download students whose portal data differs from the prepared list for
     * any tracked field (names, dob, gender, marital status, JAMB number).
     * Each row shows OLD (prepared list) vs NEW (student_nysc) side by side.
     * Read-only: nothing in the database is touched.
     */
    public function exportChanged(Request $request)
    {
        try {
            [$list, $error] = $this->loadList($request);
            if ($error !== null) {
                return response()->json(['success' => false, 'message' => $error], 404);
            }

            $portalRows = $this->portalQuery($request)->get();

            $headers = ['matric_no', 'session_id', 'last_updated', 'fields_changed'];
            foreach ($this->changeFields as $f) {
                $headers[] = 'old_' . $f;
                $headers[] = 'new_' . $f;
            }

            $out = [];
            foreach ($portalRows as $row) {
                $listRow = $list['by_key'][$this->normalizeMatric($row->matric_no)] ?? null;
                if ($listRow === null) {
                    continue;
                }

                $diffs = $this->diffForStudent($row, $listRow);
                if (empty($diffs)) {
                    continue;
                }

                $csvRow = [
                    $row->matric_no,
                    $row->nysc_session_id,
                    optional($row->updated_at)->format('d/m/Y H:i'),
                    implode(', ', array_keys($diffs)),
                ];

                foreach ($this->changeFields as $f) {
                    $csvRow[] = $diffs[$f]['old'] ?? '';
                    $csvRow[] = $diffs[$f]['new'] ?? '';
                }

                $out[] = $csvRow;
            }

            return $this->csvResponse(
                'updated_after_list_' . now()->format('Y-m-d_H-i-s') . '.csv',
                $headers,
                $out,
                [] // keep every cell verbatim so old/new pairs stay readable
            );
        } catch (\Exception $e) {
            Log::error('Changed-students export failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }

    private function resolvePath($filename): ?string
    {
        $filename = basename((string) $filename);
        if ($filename === '' || !preg_match('/^[A-Za-z0-9._\-]+$/', $filename)) {
            return null;
        }

        return $this->storageDir . '/' . $filename;
    }

    /**
     * Normalise a raw spreadsheet cell for comparison/storage: trim
     * whitespace and drop leading apostrophes. Excel uses a leading ' as an
     * invisible "treat as text" marker, and CSV round-trips (e.g. our own
     * exports that protect phone/dob leading zeros) turn it into literal
     * content — left in place it would break date parsing and name matching.
     */
    private function cleanCell($value): string
    {
        return ltrim(trim((string) $value), "'");
    }

    private function normalizeHeader(?string $header): ?string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $header));
        return $this->headerAliases[$key] ?? null;
    }

    private function normalizeMatric(?string $matric): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim((string) $matric)));
    }

    /**
     * Stream a CSV with BOM — same conventions as the other portal exports.
     */
    private function csvResponse(string $filename, array $headers, array $rows, array $textColumns)
    {
        $responseHeaders = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
            'Pragma'              => 'public',
        ];

        $callback = function () use ($headers, $rows, $textColumns) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, $headers);

            foreach ($rows as $row) {
                foreach ($textColumns as $i) {
                    if (isset($row[$i]) && $row[$i] !== '' && $row[$i] !== null) {
                        $row[$i] = '\'' . $row[$i];
                    }
                }
                fputcsv($file, $row);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $responseHeaders);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
