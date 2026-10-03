<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeStyle extends Model
{
    protected $fillable = ['shop_id', 'theme_id', 'tokens', 'analyzed_at'];

    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
