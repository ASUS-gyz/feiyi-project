<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['session_id', 'role', 'content'])]
class ChatMessage extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }
}
