<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            // Shopify's theme GID, e.g. "gid://shopify/OnlineStoreTheme/123". Re-analyzed
            // only when this changes, so switching themes is what triggers a refresh.
            $table->string('theme_id');
            // Extracted design tokens: accent, accent_contrast, radius, font_weight,
            // text_transform, letter_spacing, border_width. Any subset may be present.
            $table->json('tokens');
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_styles');
    }
};
