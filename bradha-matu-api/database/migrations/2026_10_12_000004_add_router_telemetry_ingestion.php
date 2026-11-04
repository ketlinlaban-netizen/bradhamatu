<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->char('telemetry_token_hash', 64)->nullable()->unique();
            $table->string('telemetry_snapshot_id', 128)->nullable();
            $table->timestamp('telemetry_observed_at')->nullable();
            $table->timestamp('telemetry_received_at')->nullable();
            $table->json('latest_telemetry')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropUnique(['telemetry_token_hash']);
            $table->dropColumn([
                'telemetry_token_hash',
                'telemetry_snapshot_id',
                'telemetry_observed_at',
                'telemetry_received_at',
                'latest_telemetry',
            ]);
        });
    }
};
