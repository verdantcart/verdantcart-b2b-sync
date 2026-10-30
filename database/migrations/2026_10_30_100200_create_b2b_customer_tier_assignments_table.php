<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which tier(s) a customer belongs to, optionally time-boxed
     * (e.g. a campaign tier valid for one quarter).
     */
    public function up(): void
    {
        Schema::create('b2b_customer_tier_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('b2b_customer_id')->constrained('b2b_customers')->cascadeOnDelete();
            $table->foreignId('b2b_price_tier_id')->constrained('b2b_price_tiers')->restrictOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['b2b_customer_id', 'b2b_price_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_customer_tier_assignments');
    }
};
