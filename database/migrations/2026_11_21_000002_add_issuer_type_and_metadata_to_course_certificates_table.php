<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            if (! Schema::hasColumn('course_certificates', 'issuer_type')) {
                $table->string('issuer_type', 20)->default('teacher')->after('status')->index();
            }
            if (! Schema::hasColumn('course_certificates', 'metadata')) {
                $table->json('metadata')->nullable()->after('issuer_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_certificates', function (Blueprint $table) {
            if (Schema::hasColumn('course_certificates', 'metadata')) {
                $table->dropColumn('metadata');
            }
            if (Schema::hasColumn('course_certificates', 'issuer_type')) {
                $table->dropColumn('issuer_type');
            }
        });
    }
};
