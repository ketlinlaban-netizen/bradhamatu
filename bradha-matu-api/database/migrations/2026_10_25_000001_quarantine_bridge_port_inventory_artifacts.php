<?php

use App\Support\MacAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKUP_TABLE = 'network_clients_artifact_backup';

    public function up(): void
    {
        if (! Schema::hasTable('network_clients')) {
            return;
        }

        $artifactIds = DB::table('network_clients')
            ->pluck('mac_address', 'id')
            ->filter(fn (string $mac): bool => MacAddress::isBridgePortArtifact($mac))
            ->keys()
            ->all();

        if ($artifactIds === []) {
            return;
        }

        // Preserve the exact rows being removed so the repair is reversible without a full restore.
        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            $sourceColumns = Schema::getColumns('network_clients');
            Schema::create(self::BACKUP_TABLE, function (Blueprint $table) use ($sourceColumns): void {
                $table->uuid('id')->primary();
                foreach ($sourceColumns as $column) {
                    if ($column['name'] === 'id') {
                        continue;
                    }
                    $name = $column['name'];
                    match ($name) {
                        'router_id', 'mac_address', 'connection_type', 'hostname', 'alias',
                        'ip_address', 'configured_ip_address', 'interface_id', 'interface_name',
                        'status', 'offline_reason' => $table->text($name)->nullable(),
                        'signal' => $table->integer($name)->nullable(),
                        'rx_bytes', 'tx_bytes' => $table->bigInteger($name)->nullable(),
                        'first_seen_at', 'last_seen_at', 'ip_address_observed_at',
                        'created_at', 'updated_at' => $table->timestamp($name)->nullable(),
                        default => $table->string($name, 191)->nullable(),
                    };
                }
            });
        }

        $rows = DB::table('network_clients')->whereIn('id', $artifactIds)->get();
        foreach ($rows as $row) {
            DB::table(self::BACKUP_TABLE)->updateOrInsert(
                ['id' => $row->id],
                (array) $row,
            );
        }

        DB::table('network_clients')->whereIn('id', $artifactIds)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::BACKUP_TABLE) || ! Schema::hasTable('network_clients')) {
            return;
        }

        $existing = DB::table('network_clients')->pluck('id')->all();
        foreach (DB::table(self::BACKUP_TABLE)->get() as $row) {
            if (in_array($row->id, $existing, true)) {
                continue;
            }
            DB::table('network_clients')->insert((array) $row);
        }
    }
};
