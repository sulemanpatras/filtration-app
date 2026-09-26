<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'shopify_id',
        'title',
        'handle',
        'vendor',
        'product_type',
        'tags',
        'min_price',
        'max_price',
        'compare_at_price',
        'featured_image',
        'is_available',
        'status',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'is_available' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_products');
    }
}
