<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'title', 'message', 'is_read', 'related_id'])]
class Notification extends Model
{
    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
