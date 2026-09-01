<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'content',
        'category',
        'images',
        'like_count',
        'comment_count',
        'view_count',
        'status',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'like_count' => 'integer',
            'comment_count' => 'integer',
            'view_count' => 'integer',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 查询未删除的帖子
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * 作者
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 点赞记录
     */
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /**
     * 评论
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
