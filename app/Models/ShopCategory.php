<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'sort_order'])]
class ShopCategory extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_deleted', false);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'category_id');
    }
}
