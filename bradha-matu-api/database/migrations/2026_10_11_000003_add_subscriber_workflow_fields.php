<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('account_number', 24)->nullable()->unique();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
        });

        DB::table('customers')->select(['id'])->orderBy('id')->chunkById(100, function ($customers): void {
            foreach ($customers as $customer) {
                DB::table('customers')->where('id', $customer->id)->update([
                    'account_number' => 'BM-'.strtoupper(substr(str_replace('-', '', $customer->id), 0, 10)),
                ]);
            }
        }, 'id');

        Schema::create('customer_network_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('router_id')->nullable();
            $table->uuid('network_client_id')->nullable();
            $table->string('friendly_name', 100);
            $table->string('mac_address', 32)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('source', 24)->default('manual');
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('router_id')->references('id')->on('routers')->nullOnDelete();
            $table->foreign('network_client_id')->references('id')->on('network_clients')->nullOnDelete();
            $table->index(['customer_id', 'router_id']);
            $table->unique(['router_id', 'mac_address'], 'customer_network_identity_router_mac_unique');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->uuid('network_identity_id')->nullable();
            $table->timestamp('billing_period_start_at')->nullable();
            $table->timestamp('billing_period_end_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('admin_idempotency_key', 100)->nullable()->unique();
            $table->string('admin_billing_cycle_key', 64)->nullable()->unique();
            $table->string('admin_request_fingerprint', 64)->nullable();
            $table->foreign('network_identity_id')
                ->references('id')->on('customer_network_identities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['network_identity_id']);
            $table->dropUnique(['admin_idempotency_key']);
            $table->dropColumn([
                'network_identity_id',
                'billing_period_start_at',
                'billing_period_end_at',
                'due_at',
                'admin_idempotency_key',
                'admin_billing_cycle_key',
                'admin_request_fingerprint',
            ]);
        });

        Schema::dropIfExists('customer_network_identities');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['account_number']);
            $table->dropColumn(['account_number', 'location', 'notes']);
        });
    }
};
