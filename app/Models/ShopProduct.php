<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'name', 'price', 'original_price', 'stock', 'sales_count', 'images', 'specs', 'description', 'status'])]
class ShopProduct extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'stock' => 'integer',
            'sales_count' => 'integer',
            'images' => 'array',
            'specs' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function scopeOnSale($query)
    {
        return $query->where('status', 'PRODUCT_ON');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'category_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ShopOrder::class, 'product_id');
    }
}
