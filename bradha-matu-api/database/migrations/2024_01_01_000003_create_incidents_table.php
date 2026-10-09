<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create incidents table
 *
 * Stores incident records. The RouterProvider can generate incidents
 * dynamically from current router status, or they can be persisted here
 * for historical tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('router_name');
            $table->enum('type', ['offline', 'degraded', 'high_cpu', 'high_memory', 'interface_down']);
            $table->enum('severity', ['critical', 'warning', 'info']);
            $table->text('message');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
            $table->index(['router_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
