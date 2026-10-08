<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create routers table
 *
 * Stores admin-managed router entries. Each row maps to a physical MikroTik
 * router reachable via the REST API. The MikroTikRouterProvider can read
 * from this table instead of the config file for dynamic router management.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('identity')->nullable();
            $table->string('ip_address');
            $table->string('api_username')->default('monitoring');
            $table->string('api_password');
            $table->string('location')->nullable();
            $table->string('site')->nullable();
            $table->boolean('use_ssl')->default(true);
            $table->boolean('verify_cert')->default(false);
            $table->string('status_override')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
