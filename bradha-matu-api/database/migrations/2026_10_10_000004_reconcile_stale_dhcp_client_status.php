<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('network_clients')
            ->where('connection_type', 'dhcp')
            ->where(function ($query) {
                $query->where('signal', '<', 0)
                    ->orWhere('interface_name', 'like', 'wlan%');
            })
            ->update(['connection_type' => 'wireless']);

        DB::table('network_clients')
            ->where('connection_type', 'dhcp')
            ->where('status', 'online')
            ->where(function ($query) {
                $query->whereNull('interface_name')
                    ->orWhere('interface_name', '');
            })
            ->where('signal', '>=', 0)
            ->update([
                'status' => 'offline',
                'offline_reason' => 'Previous record was based only on a DHCP lease; DHCP leases can remain bound after disconnection.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Client presence reconciliation cannot be safely reversed without restoring a database backup.');
    }
};
