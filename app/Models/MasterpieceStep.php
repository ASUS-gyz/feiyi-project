<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterpieceStep extends Model
{
    protected $fillable = [
        'masterpiece_id',
        'sort_order',
        'name',
        'description',
        'difficulty',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'difficulty' => 'integer',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 查询未删除的步骤
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * 所属名作
     */
    public function masterpiece(): BelongsTo
    {
        return $this->belongsTo(Masterpiece::class, 'masterpiece_id');
    }
}
