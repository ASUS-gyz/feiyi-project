<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'name', 'skeleton_url', 'foil_colors', 'difficulty', 'sort_order'])]
class GameTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'foil_colors' => 'array',
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
