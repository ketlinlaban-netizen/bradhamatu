<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('network_clients')) {
            return;
        }

        Schema::table('network_clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('network_clients', 'configured_ip_address')) {
                $table->string('configured_ip_address', 45)->nullable();
            }
            if (! Schema::hasColumn('network_clients', 'application_configured_ip_address')) {
                $table->string('application_configured_ip_address', 45)->nullable();
            }
            if (! Schema::hasColumn('network_clients', 'ip_address_observed_at')) {
                $table->timestamp('ip_address_observed_at')->nullable();
            }
        });

        if (Schema::hasColumn('network_clients', 'ip_address_observed_at')) {
            DB::table('network_clients')
                ->whereNotNull('ip_address')
                ->whereNull('ip_address_observed_at')
                ->update(['ip_address_observed_at' => DB::raw('last_seen_at')]);
        }
    }

    public function down(): void
    {
        // The columns may already hold reconciliation data; reversing only removes them if empty.
        Schema::table('network_clients', function (Blueprint $table): void {
            foreach (['application_configured_ip_address', 'ip_address_observed_at'] as $column) {
                if (Schema::hasColumn('network_clients', $column)
                    && DB::table('network_clients')->whereNotNull($column)->doesntExist()) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
