<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NyscPayment extends Model
{
    use HasFactory;
    
    protected $table = 'nysc_payments';
    
    protected $fillable = [
        'student_id',
        'student_nysc_id',
        'session_id',
        'amount',
        'payment_reference',
        'status',
        'payment_method',
        'payment_data',
        'payment_date',
        'transaction_id',
        'notes',
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'payment_data' => 'array',
        'payment_date' => 'datetime',
    ];
    
    /**
     * Get the student that owns this payment.
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    
    /**
     * Get the student NYSC record that owns this payment.
     */
    public function studentNysc()
    {
        return $this->belongsTo(Studentnysc::class, 'student_nysc_id');
    }
    
    /**
     * Scope for successful payments.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'successful');
    }
    
    /**
     * Scope for pending payments.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    /**
     * Scope for failed payments.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}