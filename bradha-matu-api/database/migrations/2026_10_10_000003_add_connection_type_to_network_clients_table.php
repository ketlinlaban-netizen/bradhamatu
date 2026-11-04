<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->string('connection_type', 16)->default('dhcp')->after('mac_address');
        });
    }

    public function down(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->dropColumn('connection_type');
        });
    }
};
