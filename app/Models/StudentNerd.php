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
        'email',
        'phone',
        'fname',
        'mname',
        'lname',
        'gender',
        'dob',
        'state',
        'course_study',
        'study_mode',
        'department',
        'cgpa',
        'class_of_degree',
        'graduation_year',
        'graduation_date',
    ];

    protected $casts = [
        'cgpa' => 'decimal:2',
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