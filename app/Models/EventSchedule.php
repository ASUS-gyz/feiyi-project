<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSchedule extends Model
{
    protected $table = 'event_schedules';

    protected $fillable = [
        'event_id',
        'date',
        'event',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }
}