<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'name', 'pattern_url', 'thumbnail', 'stroke_count', 'time_limit', 'difficulty', 'description', 'extra_data', 'sort_order'])]
class GameLevel extends Model
{
    protected function casts(): array
    {
        return [
            'stroke_count' => 'integer',
            'time_limit' => 'integer',
            'extra_data' => 'array',
            'sort_order' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
