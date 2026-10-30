<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per sellable SKU (simple product or variation), linking the
     * WooCommerce product to the Fortnox article.
     */
    public function up(): void
    {
        Schema::create('product_skus', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100)->unique();
            $table->unsignedBigInteger('woo_product_id')->nullable();
            $table->unsignedBigInteger('woo_variation_id')->nullable();
            $table->string('fortnox_article_number', 50)->nullable()->unique();
            $table->string('name');
            $table->decimal('base_price', 12, 2);
            $table->char('currency', 3)->default('SEK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['woo_product_id', 'woo_variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_skus');
    }
};
