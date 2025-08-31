<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudyMode extends Model
{
    use HasFactory;

    protected $table = 'study_modes';

    protected $fillable = [
        'mode',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 1,
    ];

    /**
     * Get the students that belong to this study mode.
     */
    public function students()
    {
        return $this->hasMany(StudentAcademic::class, 'study_mode_id');
    }

    /**
     * Scope a query to only include active study modes.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}