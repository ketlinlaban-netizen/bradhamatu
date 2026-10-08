<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add api_port and api_use_tls columns to routers table
 *
 * Allows the frontend configuration page to store the binary API port
 * (default 8728) and whether to use TLS (8729) per router.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->integer('api_port')->default(8728)->after('ip_address');
            $table->boolean('api_use_tls')->default(false)->after('api_port');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['api_port', 'api_use_tls']);
        });
    }
};
