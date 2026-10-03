<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $fillable = [
        'domain', 'access_token', 'scopes', 'currency', 'sync_status',
        'bulk_operation_id', 'sync_error', 'synced_at', 'uninstalled_at',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'synced_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function themeStyle(): HasOne
    {
        return $this->hasOne(ThemeStyle::class);
    }

    public function themeMarkup(): HasOne
    {
        return $this->hasOne(ThemeMarkup::class);
    }

    public function isInstalled(): bool
    {
        return $this->access_token !== null && $this->uninstalled_at === null;
    }
}
