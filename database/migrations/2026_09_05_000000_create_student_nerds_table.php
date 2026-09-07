<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the dedicated NERD table (separate from student_nysc) that the
     * nerd review flow writes to, and backfills it from existing student_nysc
     * records so the nerd page is not empty for already-registered students.
     */
    public function up(): void
    {
        if (!Schema::hasTable('student_nerds')) {
            Schema::create('student_nerds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('nysc_session_id')->nullable()->index();

                // Nerd identifying / display columns (mirrors what the nerd page shows)
                $table->string('matric_no', 50)->nullable()->index();
                $table->string('nin', 20)->nullable();
                $table->string('email', 255)->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('fname', 100)->nullable();
                $table->string('mname', 100)->nullable();
                $table->string('lname', 100)->nullable();
                $table->string('gender', 10)->nullable();
                $table->string('dob', 100)->nullable();
                $table->string('state', 100)->nullable();
                $table->string('course_study', 255)->nullable();
                $table->string('study_mode', 100)->nullable();
                $table->string('department', 255)->nullable();

                // The four fields managed by nerd review
                $table->decimal('cgpa', 5, 2)->nullable();
                $table->string('class_of_degree', 255)->nullable();
                $table->string('graduation_year', 100)->nullable();
                $table->string('graduation_date', 100)->nullable();

                $table->timestamps();
            });
        }

        // Backfill existing student_nysc records into the nerd table.
        DB::table('student_nysc')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $exists = DB::table('student_nerds')
                    ->where('student_id', $row->student_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('student_nerds')->insert([
                    'student_id' => $row->student_id,
                    'nysc_session_id' => $row->nysc_session_id ?? null,
                    'matric_no' => $row->matric_no ?? null,
                    'nin' => $row->nin ?? null,
                    'email' => $row->email ?? null,
                    'phone' => $row->phone ?? null,
                    'fname' => $row->fname ?? null,
                    'mname' => $row->mname ?? null,
                    'lname' => $row->lname ?? null,
                    'gender' => $row->gender ?? null,
                    'dob' => $row->dob ? date('Y-m-d', strtotime((string) $row->dob)) : null,
                    'state' => $row->state ?? null,
                    'course_study' => $row->course_study ?? null,
                    'study_mode' => $row->study_mode ?? null,
                    'department' => $row->department ?? null,
                    'cgpa' => $row->cgpa !== null ? round((float) $row->cgpa, 2) : null,
                    'class_of_degree' => $row->class_of_degree ?? null,
                    'graduation_year' => $row->graduation_year ?? null,
                    'graduation_date' => null,
                    'created_at' => now()->toDateTimeString(),
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
        Schema::dropIfExists('student_nerds');
    }
};