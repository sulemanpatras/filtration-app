<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'shop_id', 'shopify_id', 'handle', 'title', 'vendor', 'product_type',
        'image_url', 'image_alt', 'price_min', 'price_max', 'compare_at_price',
        'available', 'published', 'shopify_created_at',
    ];

    protected function casts(): array
    {
        return [
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'available' => 'boolean',
            'published' => 'boolean',
            'shopify_created_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }
}
