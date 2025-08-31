<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentNysc extends Model
{
    use HasFactory;

    protected $table = 'student_nysc';

    protected $fillable = [
        'student_id',
        'is_paid',
        'is_submitted',
        'submitted_at',
        // Student personal fields
        'fname',
        'lname',
        'mname',
        'gender',
        'dob',
        'marital_status',
        'phone',
        'email',
        'address',
        'state',
        'lga',
        'username',
        'matric_no',
        'department',
        'course_study',
        'level',
        'graduation_year',
        'cgpa',
        'jamb_no',
        'study_mode',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_submitted' => 'boolean',
        'dob' => 'date',
        'submitted_at' => 'datetime',
        'cgpa' => 'decimal:2',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Get the department that the student belongs to.
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Get the study mode.
     */
    public function studyMode()
    {
        return $this->belongsTo(StudyMode::class, 'study_mode_id');
    }

    /**
     * Get all payments for this NYSC record.
     */
    public function payments()
    {
        return $this->hasMany(NyscPayment::class, 'student_nysc_id');
    }

    /**
     * Get the latest successful payment.
     */
    public function latestSuccessfulPayment()
    {
        return $this->hasOne(NyscPayment::class, 'student_nysc_id')
                    ->where('status', 'successful')
                    ->latest('payment_date');
    }

    /**
     * Check if this record has any successful payments.
     */
    public function hasSuccessfulPayment()
    {
        return $this->payments()->where('status', 'successful')->exists();
    }
}
