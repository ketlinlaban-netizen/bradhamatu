<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // NAS — RADIUS clients (MikroTik routers that authenticate against FreeRADIUS)
        Schema::create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname', 128)->unique();
            $table->string('shortname', 32)->nullable();
            $table->string('type', 30)->default('other');
            $table->integer('ports')->default(0);
            $table->string('secret', 60)->default('secret');
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable();
            $table->timestamps();

            $table->index('nasname');
        });

        // radcheck — per-user authentication attributes
        Schema::create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('attribute', 64);
            $table->char('op', 2);
            $table->string('value', 253);
            $table->timestamps();

            $table->index('username');
        });

        // radreply — per-user authorization reply attributes
        Schema::create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('attribute', 64);
            $table->char('op', 2);
            $table->string('value', 253);
            $table->timestamps();

            $table->index('username');
        });

        // radgroupcheck — per-group authentication attributes
        Schema::create('radgroupcheck', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64);
            $table->string('attribute', 64);
            $table->char('op', 2);
            $table->string('value', 253);
            $table->timestamps();

            $table->index('groupname');
        });

        // radgroupreply — per-group authorization reply attributes
        Schema::create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64);
            $table->string('attribute', 64);
            $table->char('op', 2);
            $table->string('value', 253);
            $table->timestamps();

            $table->index('groupname');
        });

        // radusergroup — maps users to groups
        Schema::create('radusergroup', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('groupname', 64);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->index('username');
            $table->index('groupname');
        });

        // radacct — accounting/session log
        Schema::create('radacct', function (Blueprint $table) {
            $table->bigInteger('radacctid', true)->primary();
            $table->string('acctsessionid', 64)->default('');
            $table->string('acctuniqueid', 32)->default('')->unique();
            $table->string('username', 64)->default('');
            $table->string('groupname', 64)->default('');
            $table->string('realm', 64)->default('');
            $table->string('nasipaddress', 15)->default('');
            $table->string('nasportid', 15)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->dateTime('acctstarttime')->nullable();
            $table->dateTime('acctupdatetime')->nullable();
            $table->dateTime('acctstoptime')->nullable();
            $table->integer('acctinterval')->nullable();
            $table->bigInteger('acctsessiontime')->default(0);
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 50)->nullable();
            $table->string('connectinfo_stop', 50)->nullable();
            $table->bigInteger('acctinputoctets')->default(0);
            $table->bigInteger('acctoutputoctets')->default(0);
            $table->string('calledstationid', 50)->default('');
            $table->string('callingstationid', 50)->default('');
            $table->string('acctterminatecause', 32)->nullable();
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->string('framedipaddress', 15)->default('');
            $table->timestamps();

            $table->index('username');
            $table->index('nasipaddress');
            $table->index('acctstarttime');
            $table->index('acctstoptime');
        });

        // radpostauth — post-auth log
        Schema::create('radpostauth', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('pass', 64)->default('');
            $table->string('reply', 32)->default('');
            $table->string('authdate', 32)->default('');
            $table->timestamps();

            $table->index('username');
        });

        // radippool — IP pool for RADIUS-assigned addresses
        Schema::create('radippool', function (Blueprint $table) {
            $table->id();
            $table->string('pool_name', 30)->default('main_pool');
            $table->integer('framedipaddress')->nullable();
            $table->string('nasipaddress', 15)->default('');
            $table->string('username', 64)->default('');
            $table->string('callingstationid', 50)->default('');
            $table->string('expiry_time', 30)->nullable();
            $table->timestamps();

            $table->index('pool_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radippool');
        Schema::dropIfExists('radpostauth');
        Schema::dropIfExists('radacct');
        Schema::dropIfExists('radusergroup');
        Schema::dropIfExists('radgroupreply');
        Schema::dropIfExists('radgroupcheck');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
        Schema::dropIfExists('nas');
    }
};
