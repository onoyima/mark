<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NyscSession extends Model
{
    use HasFactory;

    protected $table = 'nysc_sessions';

    protected $fillable = [
        'name',
        'code',
        'status',
        'is_active',
        'start_at',
        'end_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Get the student NYSC records for this session.
     */
    public function studentNyscRecords()
    {
        return $this->hasMany(StudentNysc::class, 'nysc_session_id');
    }

    /**
     * Get the NYSC payments for this session.
     */
    public function payments()
    {
        return $this->hasMany(NyscPayment::class, 'nysc_session_id');
    }

    /**
     * Get the NYSC temp submissions for this session.
     */
    public function tempSubmissions()
    {
        return $this->hasMany(NyscTempSubmission::class, 'nysc_session_id');
    }

    /**
     * Get the currently active NYSC session.
     *
     * @return \App\Models\NyscSession|null
     */
    public static function activeSession()
    {
        return self::where('is_active', true)->first();
    }
}
