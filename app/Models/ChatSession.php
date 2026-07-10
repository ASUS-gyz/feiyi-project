<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['session_id', 'user_id', 'title', 'last_message'])]
class ChatSession extends Model
{
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id', 'session_id');
    }

    public static function generateSessionId(): string
    {
        return 'sess_' . Str::random(16);
    }
}
