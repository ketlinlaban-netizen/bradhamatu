<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create router_metrics table
 *
 * Stores periodic metric samples (CPU, memory, traffic rates) for
 * historical charts. A scheduled task polls each router every 5 minutes
 * and inserts a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->float('cpu_usage')->default(0);
            $table->float('memory_usage')->default(0);
            $table->float('storage_usage')->default(0);
            $table->float('temperature')->default(0);
            $table->float('rx_rate')->default(0);   // Mbps
            $table->float('tx_rate')->default(0);   // Mbps
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
            $table->index(['router_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_metrics');
    }
};
