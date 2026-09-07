<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename existing student_nerds columns to the canonical nerd-field names
     * and add the previously-missing nerd columns.
     *
     * ONLY the student_nerds table is altered. The renames are data-preserving
     * (ALTER TABLE ... CHANGE keeps existing 1685 rows). New columns start null
     * and are optionally backfilled from read-only joins on academics tables.
     *
     * Canonical mapping (rename, not duplicate):
     *   email            -> student_email
     *   phone            -> phone_number
     *   fname            -> first_name
     *   mname            -> middle_name
     *   lname            -> surname
     *   gender           -> sex
     *   dob              -> date_of_birth
     *   course_study     -> programme_major
     *   study_mode       -> programme_type
     *   class_of_degree  -> class_of_degree_text
     *   cgpa             -> final_cgpa
     *   graduation_year  -> graduation_session
     *   department       -> department_name
     */
    public function up(): void
    {
        // ------------------------------------------------------------
        // 1. Data-preserving renames (guarded: only when old column exists).
        // ------------------------------------------------------------
        $renames = [
            'email'           => 'student_email',
            'phone'           => 'phone_number',
            'fname'           => 'first_name',
            'mname'           => 'middle_name',
            'lname'           => 'surname',
            'gender'          => 'sex',
            'dob'             => 'date_of_birth',
            'course_study'    => 'programme_major',
            'study_mode'      => 'programme_type',
            'class_of_degree' => 'class_of_degree_text',
            'cgpa'            => 'final_cgpa',
            'graduation_year' => 'graduation_session',
            'department'      => 'department_name',
        ];

        $definitions = [
            'email'           => "VARCHAR(255) NULL",
            'phone'           => "VARCHAR(20) NULL",
            'fname'           => "VARCHAR(100) NULL",
            'mname'           => "VARCHAR(100) NULL",
            'lname'           => "VARCHAR(100) NULL",
            'gender'          => "VARCHAR(10) NULL",
            'dob'             => "VARCHAR(100) NULL",
            'course_study'    => "VARCHAR(255) NULL",
            'study_mode'      => "VARCHAR(100) NULL",
            'class_of_degree' => "VARCHAR(255) NULL",
            'cgpa'            => "DECIMAL(5,2) NULL",
            'graduation_year' => "VARCHAR(100) NULL",
            'department'      => "VARCHAR(255) NULL",
        ];

        foreach ($renames as $old => $new) {
            if (Schema::hasColumn('student_nerds', $old) && !Schema::hasColumn('student_nerds', $new)) {
                DB::statement("ALTER TABLE student_nerds CHANGE `{$old}` `{$new}` {$definitions[$old]}");
            }
        }

        // ------------------------------------------------------------
        // 2. New columns that never existed before.
        // ------------------------------------------------------------
        Schema::table('student_nerds', function (Blueprint $table) {
            if (!Schema::hasColumn('student_nerds', 'grade_approval_date')) {
                $table->string('grade_approval_date', 100)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'admission_date')) {
                $table->string('admission_date', 100)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'mode_of_entry')) {
                $table->string('mode_of_entry', 100)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'faculty_name')) {
                $table->string('faculty_name', 255)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'senate_meeting_ref')) {
                $table->string('senate_meeting_ref', 255)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'graduate_list_ref')) {
                $table->string('graduate_list_ref', 255)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'verified_by')) {
                $table->string('verified_by', 255)->nullable();
            }
            if (!Schema::hasColumn('student_nerds', 'remarks')) {
                $table->text('remarks')->nullable();
            }
        });

        // ------------------------------------------------------------
        // 3. One-time backfill of the new columns from read-only joins.
        //    Purely SELECT-based on the academics tables; nothing else is
        //    altered. Handles legacy traffic rules (verified_by, remarks,
        //    senate_meeting_ref, graduate_list_ref) left null.
        // ------------------------------------------------------------
        if (!Schema::hasTable('student_academics')) {
            return;
        }

        // Keyed on matric_no (the student_academics table has its own matric_no
        // and is the point of contact between the two tables).
        DB::table('student_nerds as n')
            ->leftJoin('student_academics as a', 'n.matric_no', '=', 'a.matric_no')
            ->leftJoin('faculties as f', 'a.faculty_id', '=', 'f.id')
            ->leftJoin('entry_modes as em', 'a.entry_mode_id', '=', 'em.id')
            ->whereNull('n.admission_date')
            ->whereNotNull('a.admitted_date')
            ->update([
                'admission_date' => DB::raw('DATE_FORMAT(a.admitted_date, "%Y-%m-%d")'),
                'faculty_name'   => DB::raw('f.name'),
                'mode_of_entry'  => DB::raw('em.mode'),
                'updated_at'     => now()->toDateTimeString(),
            ]);

        // grade_approval_date mirrors graduation_date (they are the same thing).
        DB::table('student_nerds')
            ->where(function ($q) {
                $q->whereNull('grade_approval_date')
                  ->orWhere('grade_approval_date', '!=', DB::raw('graduation_date'));
            })
            ->whereNotNull('graduation_date')
            ->update([
                'grade_approval_date' => DB::raw('graduation_date'),
                'updated_at'          => now()->toDateTimeString(),
            ]);
    }

    /**
     * Reverse the migrations: drop the new columns and rename back.
     * Reverses only what THIS migration did.
     */
    public function down(): void
    {
        Schema::table('student_nerds', function (Blueprint $table) {
            foreach (['remarks', 'verified_by', 'graduate_list_ref', 'senate_meeting_ref', 'faculty_name', 'mode_of_entry', 'admission_date', 'grade_approval_date'] as $column) {
                if (Schema::hasColumn('student_nerds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $renames = [
            'student_email'       => 'email',
            'phone_number'        => 'phone',
            'first_name'          => 'fname',
            'middle_name'         => 'mname',
            'surname'             => 'lname',
            'sex'                 => 'gender',
            'date_of_birth'       => 'dob',
            'programme_major'     => 'course_study',
            'programme_type'      => 'study_mode',
            'class_of_degree_text'=> 'class_of_degree',
            'final_cgpa'          => 'cgpa',
            'graduation_session'  => 'graduation_year',
            'department_name'     => 'department',
        ];

        $definitions = [
            'student_email'       => "VARCHAR(255) NULL",
            'phone_number'        => "VARCHAR(20) NULL",
            'first_name'          => "VARCHAR(100) NULL",
            'middle_name'         => "VARCHAR(100) NULL",
            'surname'             => "VARCHAR(100) NULL",
            'sex'                 => "VARCHAR(10) NULL",
            'date_of_birth'       => "VARCHAR(100) NULL",
            'programme_major'     => "VARCHAR(255) NULL",
            'programme_type'      => "VARCHAR(100) NULL",
            'class_of_degree_text'=> "VARCHAR(255) NULL",
            'final_cgpa'          => "DECIMAL(5,2) NULL",
            'graduation_session'  => "VARCHAR(100) NULL",
            'department_name'     => "VARCHAR(255) NULL",
        ];

        foreach ($renames as $new => $old) {
            if (Schema::hasColumn('student_nerds', $new) && !Schema::hasColumn('student_nerds', $old)) {
                DB::statement("ALTER TABLE student_nerds CHANGE `{$new}` `{$old}` {$definitions[$new]}");
            }
        }
    }
};