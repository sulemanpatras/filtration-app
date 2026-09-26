<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_domain',
        'access_token',
        'scope',
        'name',
        'currency',
        'currency_symbol',
        'is_active',
        'last_synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function filterSetting(): HasOne
    {
        return $this->hasOne(FilterSetting::class);
    }

    /**
     * Get or create default filter settings for this shop.
     */
    public function getOrCreateFilterSetting(): FilterSetting
    {
        return $this->filterSetting()->firstOrCreate([], [
            'enable_price' => true,
            'enable_vendor' => true,
            'enable_type' => true,
            'enable_tags' => true,
            'enable_availability' => true,
            'per_page' => 24,
            'theme_accent_color' => '#0f172a',
            'filter_layout' => 'sidebar',
            'show_product_count' => true,
            'auto_mount_on_large_collections_only' => false,
        ]);
    }
}
