<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every WooCommerce order we receive, with the tier pricing we applied
     * and the result of pushing it to the ERP.
     *
     * status: received → priced → synced | failed
     */
    public function up(): void
    {
        Schema::create('order_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_order_id')->unique();
            $table->foreignId('b2b_customer_id')->nullable()->constrained('b2b_customers')->nullOnDelete();
            $table->string('status', 20)->default('received')->index();
            $table->char('currency', 3)->default('SEK');
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->jsonb('woo_payload');            // raw webhook body, for replay/debugging
            $table->jsonb('priced_lines')->nullable(); // lines after tier pricing
            $table->string('erp_document_number', 50)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_log');
    }
};
