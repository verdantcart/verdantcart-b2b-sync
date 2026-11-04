<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OAuth tokens for the connected ERP (one row per driver). Tokens are stored encrypted.
     * Fortnox rotates the refresh token on every use, so it must live in the database, not .env.
     */
    public function up(): void
    {
        Schema::create('erp_connections', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->unique();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->string('scopes')->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_connections');
    }
};
