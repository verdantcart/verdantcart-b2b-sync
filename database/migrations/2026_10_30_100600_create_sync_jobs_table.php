<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business-level audit of each sync attempt (separate from Laravel's own
     * `jobs`/`failed_jobs` queue tables). Feeds the retry / dead-letter view.
     *
     * type:   order_push | inventory_sync | customer_pull
     * status: pending → running → succeeded | failed | dead
     */
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('status', 20)->default('pending');
            $table->nullableMorphs('syncable'); // e.g. OrderLog, ProductSku
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->jsonb('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
    }
};
