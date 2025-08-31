<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseStudy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'abb',
        'department_id',
        'pgd_program_offered_id',
        'program_offered_id',
        'masters_program_offered_id',
        'jamb_cutoff',
        'duration',
        'phd',
        'msc',
        'p_masters',
        'pgd',
        'category',
        'status',
    ];

    public function studentAcademics()
    {
        return $this->hasMany(StudentAcademic::class, 'course_study_id');
    }
}
