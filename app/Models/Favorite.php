<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Favorite extends Model
{
    /**
     * 表仅含 created_at，无 updated_at
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'target_id',
        'target_type',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
        ];
    }
}
