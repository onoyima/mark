<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the award columns to student_nerds and backfill them from each
     * student's course_study via the ProgrammeAwardService map.
     *
     * The award fields (award_title, award_short_title,
     * programme_award_combined, programme_category) are persisted on the nerd
     * table so the nerd page and any export read the stored award values rather
     * than deriving them on every request.
     */
    public function up(): void
    {
        Schema::table('student_nerds', function (Blueprint $table) {
            if (Schema::hasColumn('student_nerds', 'award_title')) {
                return;
            }
            $table->string('award_title', 255)->nullable();
            $table->string('award_short_title', 50)->nullable();
            $table->string('programme_award_combined', 255)->nullable();
            $table->string('programme_category', 100)->nullable();
        });

        // Backfill the new award columns from course_study.
        // (course_study may already have been renamed to programme_major
        //  if this ran after the canonicalize migration - read whichever exists.)
        $programmeColumn = Schema::hasColumn('student_nerds', 'course_study')
            ? 'course_study'
            : 'programme_major';
        $service = new \App\Services\ProgrammeAwardService();
        DB::table('student_nerds')
            ->whereNull('award_title')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($service, $programmeColumn) {
                foreach ($rows as $row) {
                    $award = $service->resolve($row->{$programmeColumn});
                    DB::table('student_nerds')
                        ->where('id', $row->id)
                        ->update([
                            'award_title' => $award['award_title'] ?? null,
                            'award_short_title' => $award['award_short_title'] ?? null,
                            'programme_award_combined' => $award['programme_award_combined'] ?? null,
                            'programme_category' => $award['programme_category'] ?? null,
                            'updated_at' => now()->toDateTimeString(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_nerds', function (Blueprint $table) {
            $table->dropColumn([
                'award_title',
                'award_short_title',
                'programme_award_combined',
                'programme_category',
            ]);
        });
    }
};
