<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Complaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_no',
        'user_id',
        'service_order_id',
        'order_number',
        'subject',
        'message',
        'status',
        'admin_reply',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }
}
