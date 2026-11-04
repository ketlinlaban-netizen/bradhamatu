<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('api_transport', 16)->default('binary')->after('api_port');
        });

        $defaultTransport = config('mikrotik.provider') === 'rest' ? 'rest' : 'binary';
        DB::table('routers')->update(['api_transport' => $defaultTransport]);
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('api_transport');
        });
    }
};
