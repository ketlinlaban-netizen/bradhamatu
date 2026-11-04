<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->string('alias', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_clients', function (Blueprint $table) {
            $table->dropColumn('alias');
        });
    }
};
