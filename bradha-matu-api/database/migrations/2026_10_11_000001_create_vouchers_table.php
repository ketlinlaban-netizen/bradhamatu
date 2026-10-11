<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->uuid('package_id');
            $table->string('status')->default('unused');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('batch_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('package_id')->references('id')->on('packages')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
