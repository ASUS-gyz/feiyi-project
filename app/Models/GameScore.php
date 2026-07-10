<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'game_type', 'level_id', 'level_name', 'score', 'duration', 'difficulty', 'metadata', 'certificate_url'])]
class GameScore extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'duration' => 'integer',
            'metadata' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
