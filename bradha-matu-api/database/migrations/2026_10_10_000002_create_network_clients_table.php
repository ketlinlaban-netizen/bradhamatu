<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('router_id');
            $table->string('mac_address', 32);
            $table->string('hostname')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('interface_id')->nullable();
            $table->string('interface_name')->nullable();
            $table->smallInteger('signal')->nullable();
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('status', 16)->default('online');
            $table->text('offline_reason')->nullable();
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->cascadeOnDelete();
            $table->unique(['router_id', 'mac_address']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_clients');
    }
};
