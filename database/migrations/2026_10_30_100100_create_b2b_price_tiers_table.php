<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer segments ("Gold", "Wholesale A"...). v0.1 pricing model:
     * tier price = product_skus.base_price * (1 - discount_percent / 100).
     */
    public function up(): void
    {
        Schema::create('b2b_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('priority')->default(0); // higher wins if a customer has several tiers
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_price_tiers');
    }
};
