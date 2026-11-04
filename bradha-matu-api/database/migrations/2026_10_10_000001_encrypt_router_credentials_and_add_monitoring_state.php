<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->text('api_password')->change();
            $table->boolean('monitoring_enabled')->default(true);
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('last_routeros_version')->nullable();
            $table->string('last_error_category')->nullable();
        });

        DB::table('routers')->select(['id', 'api_password'])->orderBy('id')->each(
            function (object $router): void {
                $password = (string) $router->api_password;

                try {
                    Crypt::decryptString($password);
                } catch (DecryptException) {
                    DB::table('routers')
                        ->where('id', $router->id)
                        ->update(['api_password' => Crypt::encryptString($password)]);
                }
            }
        );
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Credential encryption cannot be rolled back without restoring a secure database backup.',
        );
    }
};
