<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'name',
        'slug',
        'description',
        'price',
        'required_fields',
        'is_active',
    ];

    protected $casts = [
        'required_fields' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
