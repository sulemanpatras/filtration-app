<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThemeMarkup extends Model
{
    protected $fillable = ['shop_id', 'theme_id', 'donor_handle', 'section_id', 'templates', 'analyzed_at'];

    protected function casts(): array
    {
        return [
            'templates' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
