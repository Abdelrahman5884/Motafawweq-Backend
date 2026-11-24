<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('republic_leagues', function (Blueprint $table): void {
            if (! Schema::hasColumn('republic_leagues', 'grade_ar')) {
                $table->string('grade_ar', 100)->nullable()->after('title_ar');
                $table->index(['is_active', 'grade_ar', 'starts_at'], 'republic_leagues_active_grade_idx');
            }
        });

        Schema::table('platform_champions', function (Blueprint $table): void {
            if (! Schema::hasColumn('platform_champions', 'grade_ar')) {
                $table->string('grade_ar', 100)->nullable()->after('title_en');
                $table->index('grade_ar', 'platform_champions_grade_idx');
            }

            if (! Schema::hasColumn('platform_champions', 'republic_league_id')) {
                $table->foreignId('republic_league_id')
                    ->nullable()
                    ->after('student_id')
                    ->constrained('republic_leagues')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('platform_champions', 'source')) {
                $table->string('source', 30)->default('manual')->after('republic_league_id');
            }

            if (! Schema::hasColumn('platform_champions', 'approved_by')) {
                $table->foreignId('approved_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('platform_champions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }

            $table->index(['student_id', 'republic_league_id'], 'platform_champions_student_league_idx');
            $table->index(['is_pinned', 'grade_ar', 'display_order'], 'platform_champions_pinned_grade_idx');
        });
    }

    public function down(): void
    {
        Schema::table('platform_champions', function (Blueprint $table): void {
            $table->dropIndex('platform_champions_student_league_idx');
            $table->dropIndex('platform_champions_pinned_grade_idx');

            if (Schema::hasColumn('platform_champions', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }
            if (Schema::hasColumn('platform_champions', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
            if (Schema::hasColumn('platform_champions', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('platform_champions', 'republic_league_id')) {
                $table->dropConstrainedForeignId('republic_league_id');
            }
            if (Schema::hasColumn('platform_champions', 'grade_ar')) {
                $table->dropIndex('platform_champions_grade_idx');
                $table->dropColumn('grade_ar');
            }
        });

        Schema::table('republic_leagues', function (Blueprint $table): void {
            if (Schema::hasColumn('republic_leagues', 'grade_ar')) {
                $table->dropIndex('republic_leagues_active_grade_idx');
                $table->dropColumn('grade_ar');
            }
        });
    }
};
