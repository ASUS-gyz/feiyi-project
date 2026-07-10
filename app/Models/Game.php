<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'title', 'description', 'icon', 'cover_image', 'default_difficulty', 'difficulty_options', 'features', 'rules', 'is_enabled'])]
class Game extends Model
{
    protected function casts(): array
    {
        return [
            'difficulty_options' => 'array',
            'features' => 'array',
            'rules' => 'array',
            'is_enabled' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(GameLevel::class, 'game_id');
    }

    public function templates(): HasMany
    {
        return $this->hasMany(GameTemplate::class, 'game_id');
    }
}
