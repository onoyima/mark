<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class NyscTempSubmission extends Model
{
    use HasFactory;

    protected $table = 'nysc_temp_submissions';

    protected $fillable = [
        'student_id',
        'session_id',
        // Personal Information
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
        // Academic Information
        'matric_no',
        'department',
        'course_study',
        'level',
        'graduation_year',
        'cgpa',
        'jamb_no',
        'study_mode',
        // Status
        'status',
        'expires_at',
    ];

    protected $casts = [
        'dob' => 'date',
        'cgpa' => 'decimal:2',
        'graduation_year' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * Relationship with Student model
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    /**
     * Generate a unique session ID
     */
    public static function generateSessionId()
    {
        return 'NYSC-TEMP-' . uniqid() . '-' . time();
    }

    /**
     * Set expiration time (24 hours from now)
     */
    public function setExpirationTime()
    {
        $this->expires_at = Carbon::now()->addHours(24);
        $this->save();
    }

    /**
     * Check if the submission has expired
     */
    public function isExpired()
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Alias for isExpired() method for backward compatibility
     */
    public function hasExpired()
    {
        return $this->isExpired();
    }

    /**
     * Scope to get non-expired submissions
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', Carbon::now())
                    ->orWhereNull('expires_at');
    }

    /**
     * Scope to get expired submissions for cleanup
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }

    /**
     * Convert temp submission data to format suitable for student_nysc table
     */
    public function toStudentNyscData()
    {
        $data = $this->toArray();

        // Remove fields that don't belong in student_nysc table
        unset($data['id'], $data['session_id'], $data['status'], $data['expires_at'],
              $data['created_at'], $data['updated_at']);

        // Add submission tracking fields
        $data['is_submitted'] = true;

        return $data;
    }
}
