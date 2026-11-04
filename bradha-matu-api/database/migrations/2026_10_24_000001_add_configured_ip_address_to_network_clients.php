<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->string('configured_ip_address', 45)->nullable();
            $table->string('application_configured_ip_address', 45)->nullable();
            $table->timestamp('ip_address_observed_at')->nullable();
        });

        DB::table('network_clients')
            ->whereNotNull('ip_address')
            ->update(['ip_address_observed_at' => DB::raw('last_seen_at')]);
    }

    public function down(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->dropColumn([
                'configured_ip_address',
                'application_configured_ip_address',
                'ip_address_observed_at',
            ]);
        });
    }
};
