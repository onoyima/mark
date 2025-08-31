<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'country_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the country that owns the state.
     */
    // public function country()
    // {
    //     return $this->belongsTo(Country::class);
    // }

    /**
     * Get the students for the state.
     */
    public function students()
    {
        return $this->hasMany(Student::class, 'state_id');
    }
}
