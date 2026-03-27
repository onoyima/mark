<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NyscDataEditHistory extends Model
{
    protected $fillable = [
        'student_nysc_id',
        'admin_id',
        'nysc_session_id',
        'old_data',
        'new_data'
    ];

    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
    ];
}
