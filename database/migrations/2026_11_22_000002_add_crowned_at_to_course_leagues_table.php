<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_leagues', function (Blueprint $table) {
            if (! Schema::hasColumn('course_leagues', 'crowned_at')) {
                $table->timestamp('crowned_at')->nullable()->after('ends_at')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_leagues', function (Blueprint $table) {
            if (Schema::hasColumn('course_leagues', 'crowned_at')) {
                $table->dropColumn('crowned_at');
            }
        });
    }
};
