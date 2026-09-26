<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->unique();
            $table->text('access_token')->nullable();
            $table->string('scope')->nullable();
            $table->string('name')->nullable();
            $table->string('currency')->default('USD');
            $table->string('currency_symbol')->default('$');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('shop_domain');
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('shopify_id');
            $table->string('title');
            $table->string('handle');
            $table->integer('products_count')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_id']);
            $table->index(['shop_id', 'handle']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('shopify_id');
            $table->string('title');
            $table->string('handle');
            $table->string('vendor')->nullable()->index();
            $table->string('product_type')->nullable()->index();
            $table->json('tags')->nullable();
            $table->decimal('min_price', 10, 2)->default(0.00)->index();
            $table->decimal('max_price', 10, 2)->default(0.00)->index();
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->text('featured_image')->nullable();
            $table->boolean('is_available')->default(true)->index();
            $table->string('status')->default('active');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_id']);
            $table->index(['shop_id', 'handle']);
            $table->index(['shop_id', 'is_available', 'min_price']);
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('shopify_id');
            $table->string('title');
            $table->decimal('price', 10, 2)->default(0.00);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->string('sku')->nullable();
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->integer('inventory_quantity')->default(0);
            $table->text('image_url')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index('shopify_id');
            $table->index('product_id');
        });

        Schema::create('collection_products', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->primary(['collection_id', 'product_id']);
            $table->index(['collection_id', 'product_id']);
        });

        Schema::create('filter_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->boolean('enable_price')->default(true);
            $table->boolean('enable_vendor')->default(true);
            $table->boolean('enable_type')->default(true);
            $table->boolean('enable_tags')->default(true);
            $table->boolean('enable_availability')->default(true);
            $table->text('custom_tag_prefixes')->nullable(); // e.g. "Size, Color, Material"
            $table->integer('per_page')->default(24);
            $table->string('theme_accent_color')->default('#0f172a');
            $table->string('filter_layout')->default('sidebar'); // 'sidebar' or 'drawer'
            $table->boolean('show_product_count')->default(true);
            $table->boolean('auto_mount_on_large_collections_only')->default(false); // true: only if >5000 products, false: active for all collections
            $table->timestamps();

            $table->unique('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('filter_settings');
        Schema::dropIfExists('collection_products');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('shops');
    }
};
