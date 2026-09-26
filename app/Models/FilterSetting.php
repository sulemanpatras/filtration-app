<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FilterSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'enable_price',
        'enable_vendor',
        'enable_type',
        'enable_tags',
        'enable_availability',
        'custom_tag_prefixes',
        'per_page',
        'theme_accent_color',
        'filter_layout',
        'show_product_count',
        'auto_mount_on_large_collections_only',
    ];

    protected $casts = [
        'enable_price' => 'boolean',
        'enable_vendor' => 'boolean',
        'enable_type' => 'boolean',
        'enable_tags' => 'boolean',
        'enable_availability' => 'boolean',
        'show_product_count' => 'boolean',
        'auto_mount_on_large_collections_only' => 'boolean',
        'per_page' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
