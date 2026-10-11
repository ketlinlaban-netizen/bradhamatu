<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('balance_minor')->default(0);
            $table->boolean('auto_renewal')->default(false);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->unique();
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('type', 32);
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->text('description')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->uuid('purchase_id')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('purchase_id')->references('id')->on('purchases')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable();
            $table->string('to_phone', 32);
            $table->string('template', 100);
            $table->text('body');
            $table->string('status', 32)->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('variables')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->longText('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('wallet_transactions');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn(['invoice_number', 'subtotal_minor', 'tax_minor']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['balance_minor', 'auto_renewal']);
        });
    }
};
