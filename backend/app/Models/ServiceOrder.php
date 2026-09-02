<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'service_id',
        'input_data',
        'amount',
        'payment_method',
        'payment_status',
        'order_status',
        'rejection_reason',
        'payment_proof_image',
        'utr_number',
        'delivery_file',
        'admin_notes',
    ];

    protected $casts = [
        'input_data' => 'array',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }
}
