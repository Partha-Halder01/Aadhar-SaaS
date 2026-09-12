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
        'doc_request_title',
        'doc_request_message',
        'doc_request_status',
        'doc_request_requested_at',
        'doc_response_file',
        'doc_response_notes',
        'doc_response_submitted_at',
    ];

    protected $casts = [
        'input_data' => 'array',
        'amount' => 'decimal:2',
        'doc_request_requested_at' => 'datetime',
        'doc_response_submitted_at' => 'datetime',
    ];

    protected $appends = [
        'all_documents',
    ];

    public function getAllDocumentsAttribute()
    {
        $docs = [];
        $seen = [];

        // 1. Documents in input_data (initial uploads & any added docs)
        $inputData = $this->input_data ?: [];
        foreach ($inputData as $key => $val) {
            if ($key === '_all_uploaded_files') continue;
            if (is_string($val) && (str_starts_with($val, 'orders/') || preg_match('/\.(pdf|jpg|jpeg|png)$/i', $val))) {
                if (!isset($seen[$val])) {
                    $seen[$val] = true;
                    $isReq = str_starts_with($key, 'additional_doc') || str_starts_with($key, 'requested_doc');
                    $docs[] = [
                        'type' => $isReq ? 'requested' : 'initial',
                        'key' => $key,
                        'label' => $isReq ? ($this->doc_request_title ?: 'Requested Additional Document') : ucwords(str_replace('_', ' ', $key)),
                        'file' => $val,
                        'notes' => $isReq ? $this->doc_response_notes : null,
                        'date' => $isReq ? ($this->doc_response_submitted_at ?: $this->updated_at) : $this->created_at,
                    ];
                }
            }
        }

        // 2. doc_response_file if not already in docs
        if ($this->doc_response_file && !isset($seen[$this->doc_response_file])) {
            $seen[$this->doc_response_file] = true;
            $docs[] = [
                'type' => 'requested',
                'key' => 'doc_response_file',
                'label' => $this->doc_request_title ?: 'Requested Additional Document',
                'file' => $this->doc_response_file,
                'notes' => $this->doc_response_notes,
                'date' => $this->doc_response_submitted_at ?: $this->updated_at,
            ];
        }

        return $docs;
    }

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
