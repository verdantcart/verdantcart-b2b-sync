<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B subtotal minus what WooCommerce charged, set when an order is priced.
 * 0.00 = the shop showed the right price. Anything else needs a look: the shop may have
 * charged list price while B2B Sync was unreachable, or a tier changed mid-checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_log', function (Blueprint $table) {
            $table->decimal('price_difference', 12, 2)->nullable()->after('priced_lines');
        });
    }

    public function down(): void
    {
        Schema::table('order_log', function (Blueprint $table) {
            $table->dropColumn('price_difference');
        });
    }
};
