<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RazorpayWebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event',
        'payload',
        'processed_status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
