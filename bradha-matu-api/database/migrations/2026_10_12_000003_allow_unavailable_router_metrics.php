<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const METRIC_COLUMNS = [
        'cpu_usage',
        'memory_usage',
        'storage_usage',
        'temperature',
        'rx_rate',
        'tx_rate',
    ];

    public function up(): void
    {
        Schema::table('router_metrics', function (Blueprint $table) {
            foreach (self::METRIC_COLUMNS as $column) {
                $table->float($column)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        foreach (self::METRIC_COLUMNS as $column) {
            DB::table('router_metrics')->whereNull($column)->update([$column => 0]);
        }

        Schema::table('router_metrics', function (Blueprint $table) {
            foreach (self::METRIC_COLUMNS as $column) {
                $table->float($column)->default(0)->change();
            }
        });
    }
};
