<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_id');
            $table->string('provider')->default('mpesa');
            $table->string('provider_request_id')->nullable();
            $table->string('provider_checkout_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('state')->default('created');
            $table->unsignedBigInteger('amount_minor');
            $table->string('phone_normalized');
            $table->string('idempotency_key')->unique();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->json('sanitized_evidence')->nullable();
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            $table->index(['state', 'created_at']);
            $table->index('provider_checkout_id');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
