<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommentLike extends Model
{
    /**
     * 表仅含 created_at，无 updated_at
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'comment_id',
    ];
}
