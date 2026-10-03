<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_markups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('theme_id');
            // The under-threshold collection whose real, rendered filter HTML was cloned.
            $table->string('donor_handle');
            $table->string('section_id');
            // { shell, group, row, price } — HTML snippets with {{PLACEHOLDER}} markers.
            // Any key may be null if that piece wasn't confidently found.
            $table->json('templates');
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_markups');
    }
};
