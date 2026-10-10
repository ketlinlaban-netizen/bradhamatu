<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('phone_normalized')->unique();
            $table->string('phone_display');
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('status')->default('active');
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('policy_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
