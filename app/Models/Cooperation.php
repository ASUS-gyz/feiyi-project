<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cooperation extends Model
{
    protected $fillable = [
        'title',
        'description',
        'deadline',
        'status',
        'rules',
        'rewards',
        'images',
        'submission_count',
        'is_deleted',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date:Y-m-d',
            'images' => 'array',
            'submission_count' => 'integer',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * 查询未删除的项目
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * 提交记录
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(CooperationSubmission::class);
    }
}
