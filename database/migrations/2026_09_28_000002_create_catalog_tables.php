<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->string('handle');
            $table->string('title');
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->string('image_alt')->nullable();
            $table->decimal('price_min', 12, 2)->default(0);
            $table->decimal('price_max', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->boolean('available')->default(false);
            $table->boolean('published')->default(false);
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_id']);
            $table->index(['shop_id', 'published', 'price_min']);
            $table->index(['shop_id', 'vendor']);
            $table->index(['shop_id', 'product_type']);
        });

        Schema::create('product_tags', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('tag');

            $table->primary(['product_id', 'tag']);
            $table->index('tag');
        });

        Schema::create('product_options', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('value');

            $table->primary(['product_id', 'name', 'value']);
            $table->index(['name', 'value']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_id');
            $table->string('handle');
            $table->string('title');
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_id']);
            $table->index(['shop_id', 'handle']);
        });

        Schema::create('collection_product', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['collection_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('product_options');
        Schema::dropIfExists('product_tags');
        Schema::dropIfExists('products');
    }
};
