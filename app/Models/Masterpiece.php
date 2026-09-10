<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Masterpiece extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'period',
        'school',
        'icon',
        'description',
        'time_making',
        'foil_used',
        'difficulty',
        'background',
        'technique',
        'story',
        'value',
        'cover_image',
        'images',
        'like_count',
        'view_count',
        'status',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'difficulty' => 'integer',
            'like_count' => 'integer',
            'view_count' => 'integer',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 查询未删除的名作
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * 归属作者（可空：NULL 为无主策展名作）
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 制作步骤
     */
    public function steps(): HasMany
    {
        return $this->hasMany(MasterpieceStep::class)->orderBy('sort_order');
    }

    /**
     * 点赞记录
     */
    public function likes(): HasMany
    {
        return $this->hasMany(MasterpieceLike::class);
    }
}
