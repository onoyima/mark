<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Nerd graduate record. Separate from student_nysc: the nerd review flow
 * only ever writes here, and the student confirm/update flow mirrors nerd
 * fields into this table whenever student_nysc is updated.
 */
class StudentNerd extends Model
{
    use HasFactory;

    protected $table = 'student_nerds';

    protected $fillable = [
        'student_id',
        'nysc_session_id',
        'matric_no',
        'nin',
        'student_email',
        'phone_number',
        'first_name',
        'middle_name',
        'surname',
        'sex',
        'date_of_birth',
        'state',
        'programme_major',
        'award_title',
        'award_short_title',
        'programme_award_combined',
        'programme_category',
        'programme_type',
        'class_of_degree_text',
        'final_cgpa',
        'graduation_session',
        'graduation_date',
        'grade_approval_date',
        'admission_date',
        'mode_of_entry',
        'faculty_name',
        'department_name',
        'senate_meeting_ref',
        'graduate_list_ref',
        'verified_by',
        'remarks',
    ];

    protected $casts = [
        'final_cgpa' => 'decimal:2',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function session()
    {
        return $this->belongsTo(NyscSession::class, 'nysc_session_id');
    }
}