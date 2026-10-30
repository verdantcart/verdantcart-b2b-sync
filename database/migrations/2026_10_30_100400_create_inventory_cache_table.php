<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buffered stock per SKU. The ERP owns warehouse_quantity; we hold back a
     * safety buffer and open reservations, and push available_quantity to Woo.
     */
    public function up(): void
    {
        // SQLite (used by the test suite) has no GREATEST(); its multi-arg MAX() is equivalent.
        $max = Schema::getConnection()->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

        Schema::create('inventory_cache', function (Blueprint $table) use ($max) {
            $table->id();
            $table->foreignId('product_sku_id')->unique()->constrained('product_skus')->cascadeOnDelete();
            $table->integer('warehouse_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('buffer_quantity')->default(0);
            $table->integer('available_quantity')
                ->storedAs("{$max}(warehouse_quantity - reserved_quantity - buffer_quantity, 0)");
            $table->timestamp('erp_synced_at')->nullable();
            $table->timestamp('woo_pushed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cache');
    }
};
