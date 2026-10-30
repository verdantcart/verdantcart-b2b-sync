<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B2B buyers (companies) mirrored from WooCommerce and matched to Fortnox.
     */
    public function up(): void
    {
        Schema::create('b2b_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woo_customer_id')->nullable()->unique();
            $table->string('fortnox_customer_number', 50)->nullable()->unique();
            $table->string('company_name');
            $table->string('org_number', 20)->nullable()->index(); // Swedish organisationsnummer
            $table->string('vat_number', 30)->nullable();
            $table->string('email')->index();
            $table->char('country_code', 2)->default('SE');
            $table->char('currency', 3)->default('SEK');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_customers');
    }
};
