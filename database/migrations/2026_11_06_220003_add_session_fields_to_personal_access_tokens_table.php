<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('type', 16)->default('access')->after('name');   // access | refresh
            $table->uuid('session_id')->nullable()->after('type');          // pairs access+refresh of one device
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->string('user_agent', 512)->nullable()->after('ip_address');

            $table->index(['session_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'type']);
            $table->dropColumn(['type', 'session_id', 'ip_address', 'user_agent']);
        });
    }
};

