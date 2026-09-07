<?php

/*
 * One-time backfill of the award columns on the existing student_nerds rows.
 *
 * The migration `2026_09_07_131354_add_award_fields_to_student_nerds.php`
 * adds the columns AND backfills them automatically when you run
 * `php artisan migrate`. This script exists for environments where you want
 * to backfill WITHOUT running the structural migration (e.g. a fresh SQL
 * import path that already includes the columns), or where you added the
 * columns manually via SQL:
 *
 *   ALTER TABLE student_nerds
 *     ADD COLUMN award_title VARCHAR(255) NULL AFTER programme_major,
 *     ADD COLUMN award_short_title VARCHAR(50) NULL AFTER award_title,
 *     ADD COLUMN programme_award_combined VARCHAR(255) NULL AFTER award_short_title,
 *     ADD COLUMN programme_category VARCHAR(100) NULL AFTER programme_award_combined;
 *
 * Run: php database/sql/backfill_student_nerds_awards.php
 *
 * Only touches student_nerds. Idempotent.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\ProgrammeAwardService;

$service = new ProgrammeAwardService();

$total = 0;
$updated = 0;
$noAward = 0;

DB::table('student_nerds')
    ->select(['id', 'programme_major'])
    ->orderBy('id')
    ->chunkById(500, function ($rows) use ($service, &$total, &$updated, &$noAward) {
        foreach ($rows as $row) {
            $total++;
            $award = $service->resolve($row->programme_major);
            if ($award === null) {
                // Leave award columns null when the programme has no mapping.
                $noAward++;
                continue;
            }
            $affected = DB::table('student_nerds')
                ->where('id', $row->id)
                ->update([
                    'award_title' => $award['award_title'],
                    'award_short_title' => $award['award_short_title'],
                    'programme_award_combined' => $award['programme_award_combined'],
                    'programme_category' => $award['programme_category'],
                    'updated_at' => now()->toDateTimeString(),
                ]);
            if ($affected > 0) {
                $updated++;
            }
        }
    });

echo "Total processed: {$total}\n";
echo "Award fields written: {$updated}\n";
echo "No mapping (left null): {$noAward}\n";