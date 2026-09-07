<?php

/*
 * One-time nerd backfill (data only, after the canonical column DDL has been
 * applied). Only touches student_nerds. Idempotent.
 *
 * Steps completed in the canonicalize migration but runnable standalone:
 *   1. Finish award backfill for rows where award_title IS NULL.
 *   2. Backfill admission_date / faculty_name / mode_of_entry from
 *      student_academics keyed on matric_no (faculty + entry mode resolved
 *      from the faculties / entry_modes tables).
 *   3. Sync grade_approval_date = graduation_date.
 *   4. Bump updated_at on every affected row.
 *
 * Run: php database/sql/backfill_student_nerds_canonical.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\ProgrammeAwardService;

$service = new ProgrammeAwardService();
$now = now()->toDateTimeString();

// ------------------------------------------------------------
// 1. Award backfill for rows still missing it.
// ------------------------------------------------------------
$awardDone = 0;
$awardSkipped = 0;
DB::table('student_nerds')
    ->whereNull('award_title')
    ->select(['id', 'programme_major'])
    ->orderBy('id')
    ->chunkById(500, function ($rows) use ($service, $now, &$awardDone, &$awardSkipped) {
        foreach ($rows as $row) {
            $award = $service->resolve($row->programme_major);
            if ($award === null) {
                $awardSkipped++;
                continue;
            }
            DB::table('student_nerds')->where('id', $row->id)->update([
                'award_title'               => $award['award_title'],
                'award_short_title'         => $award['award_short_title'],
                'programme_award_combined'  => $award['programme_award_combined'],
                'programme_category'        => $award['programme_category'],
                'updated_at'                => $now,
            ]);
            $awardDone++;
        }
    });

echo "Award backfill: filled {$awardDone}, no-remaining-mapping(now-null) {$awardSkipped}\n";

// ------------------------------------------------------------
// 2. Academics backfill keyed on matric_no (student_academics.matric_no is the
//    point of contact). Uses raw SQL so the updated_at assignment target is
//    unambiguously qualified to the nerd table (multi-table UPDATE).
// ------------------------------------------------------------
$acad = DB::update(
    "UPDATE student_nerds n
     LEFT JOIN student_academics a ON n.matric_no = a.matric_no
     LEFT JOIN entry_modes em ON a.entry_mode_id = em.id
     SET n.mode_of_entry = em.mode, n.updated_at = ?
     WHERE n.mode_of_entry IS NULL AND a.entry_mode_id IS NOT NULL",
    [$now]
);
echo "mode_of_entry backfilled: {$acad} (keyed on matric_no)\n";

$acadAdm = DB::update(
    "UPDATE student_nerds n
     LEFT JOIN student_academics a ON n.matric_no = a.matric_no
     SET n.admission_date = DATE_FORMAT(a.admitted_date, '%Y-%m-%d'), n.updated_at = ?
     WHERE n.admission_date IS NULL AND a.admitted_date IS NOT NULL",
    [$now]
);
echo "admission_date backfilled: {$acadAdm}\n";

$acadFac = DB::update(
    "UPDATE student_nerds n
     LEFT JOIN student_academics a ON n.matric_no = a.matric_no
     LEFT JOIN faculties f ON a.faculty_id = f.id
     SET n.faculty_name = f.name, n.updated_at = ?
     WHERE n.faculty_name IS NULL AND a.faculty_id IS NOT NULL",
    [$now]
);
echo "faculty_name backfilled: {$acadFac}\n";

// ------------------------------------------------------------
// 3. grade_approval_date mirrors graduation_date.
// ------------------------------------------------------------
$gad = DB::table('student_nerds')
    ->where(function ($q) {
        $q->whereNull('grade_approval_date')
          ->orWhere('grade_approval_date', '!=', DB::raw('graduation_date'));
    })
    ->whereNotNull('graduation_date')
    ->update([
        'grade_approval_date' => DB::raw('graduation_date'),
        'updated_at'          => $now,
    ]);
echo "grade_approval_date synced from graduation_date: {$gad}\n";

echo "Done.\n";
