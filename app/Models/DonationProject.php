<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DonationProject extends Model
{
    protected $table = 'donation_projects';

    protected $fillable = [
        'title',
        'description',
        'target_amount',
        'current_amount',
        'supporter_count',
        'image',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'float',
            'current_amount' => 'float',
            'supporter_count' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_deleted', false)->where('status', 'PROJECT_ACTIVE');
    }
}