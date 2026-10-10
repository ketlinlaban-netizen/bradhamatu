<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_minor')->comment('Price in KSh cents (integer)');
            $table->unsignedInteger('duration_minutes');
            $table->string('access_type')->default('hotspot')->comment('hotspot or pppoe');
            $table->unsignedInteger('download_limit_kbps')->default(0)->comment('0 = unlimited');
            $table->unsignedInteger('upload_limit_kbps')->default(0)->comment('0 = unlimited');
            $table->unsignedInteger('quota_mb')->nullable()->comment('Data cap in MB, null = unlimited');
            $table->unsignedInteger('max_devices')->default(1);
            $table->uuid('router_id')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('routers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
