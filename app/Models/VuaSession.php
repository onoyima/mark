<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VuaSession extends Model
{
    use HasFactory;

    protected $table = 'vu_sessions';

    protected $fillable = [
        'session',
        'status',
        'start_date',
        'end_date',
    ];

    /**
     * Scope to get active session
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
