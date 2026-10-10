<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_id');
            $table->uuid('customer_id');
            $table->uuid('router_id')->nullable();
            $table->string('username');
            $table->string('password_hash');
            $table->string('access_type')->default('hotspot');
            $table->unsignedInteger('rate_limit_rx_kbps')->default(0);
            $table->unsignedInteger('rate_limit_tx_kbps')->default(0);
            $table->string('activation_status')->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');
            $table->index(['customer_id', 'activation_status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
    }
};
