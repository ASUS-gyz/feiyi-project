<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_no', 'user_id', 'product_id', 'product_name', 'product_image', 'quantity', 'price', 'total_amount', 'status', 'address', 'contact_name', 'contact_phone', 'remark', 'paid_at', 'shipped_at', 'completed_at', 'cancelled_at'])]
class ShopOrder extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class, 'product_id');
    }

    public static function generateOrderNo(): string
    {
        return 'ORD' . now()->format('YmdHis') . str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
