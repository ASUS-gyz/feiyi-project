<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Base extends Model
{
    protected $table = 'bases';

    protected $fillable = [
        'name',
        'location',
        'latitude',
        'longitude',
        'status',
        'booking_type',
        'booking_value',
        'courses',
        'description',
        'contact',
        'phone',
        'opening_hours',
        'images',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'images' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * 查询未删除的基地
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }
}