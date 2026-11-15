<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings an admin changes in the app (the setup guide), as opposed to .env / Fly secrets.
 * Also remembers the result of the last WooCommerce and Fortnox checks, so the setup page
 * can show them without calling either API on every page load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
