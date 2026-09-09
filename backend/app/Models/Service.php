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
        'icon_type',
        'icon',
        'icon_image',
        'icon_bg',
        'icon_color',
        'btn_text',
        'btn_icon',
        'price',
        'required_fields',
        'is_active',
    ];

    protected $appends = [
        'icon_image_url',
    ];

    protected $casts = [
        'required_fields' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getIconImageUrlAttribute(): ?string
    {
        if (!$this->icon_image) {
            return null;
        }
        if (str_starts_with($this->icon_image, 'http://') || str_starts_with($this->icon_image, 'https://')) {
            return $this->icon_image;
        }
        return asset('storage/' . ltrim($this->icon_image, '/'));
    }

    public function orders()
    {
        return $this->hasMany(ServiceOrder::class);
    }
}
