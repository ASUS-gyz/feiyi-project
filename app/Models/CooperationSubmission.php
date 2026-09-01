<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperationSubmission extends Model
{
    protected $fillable = [
        'user_id',
        'cooperation_id',
        'title',
        'description',
        'images',
        'author_name',
        'status',
        'feedback',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 查询未删除的提交
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * 所属共创项目
     */
    public function cooperation(): BelongsTo
    {
        return $this->belongsTo(Cooperation::class, 'cooperation_id');
    }

    /**
     * 提交人
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
