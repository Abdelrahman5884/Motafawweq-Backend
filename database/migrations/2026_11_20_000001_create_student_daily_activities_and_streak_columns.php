<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_daily_activities')) {
            Schema::create('student_daily_activities', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('student_id')
                    ->constrained('users')
                    ->cascadeOnDelete();

                // Student's local calendar date (YYYY-MM-DD)
                $table->date('activity_date');
                // Cumulative server-validated active platform time in seconds (capped at 86400/day)
                $table->unsignedInteger('active_seconds')->default(0);
                // Number of distinct visits/sessions opened on this calendar day
                $table->unsignedInteger('visits_count')->default(1);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_heartbeat_at')->nullable();

                $table->timestamps();

                $table->unique(['student_id', 'activity_date'], 'std_daily_activity_student_date_unique');
                $table->index(['student_id', 'activity_date'], 'std_daily_activity_student_date_idx');
            });
        }

        if (Schema::hasTable('student_profiles')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('student_profiles', 'current_streak_days')) {
                    $table->unsignedInteger('current_streak_days')->default(0)->after('target_score_percent');
                }
                if (! Schema::hasColumn('student_profiles', 'longest_streak_days')) {
                    $table->unsignedInteger('longest_streak_days')->default(0)->after('current_streak_days');
                }
                if (! Schema::hasColumn('student_profiles', 'last_active_date')) {
                    $table->date('last_active_date')->nullable()->after('longest_streak_days');
                }
                if (! Schema::hasColumn('student_profiles', 'timezone')) {
                    $table->string('timezone', 64)->default('Africa/Cairo')->after('last_active_date');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_daily_activities');

        if (Schema::hasTable('student_profiles')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                foreach (['current_streak_days', 'longest_streak_days', 'last_active_date', 'timezone'] as $col) {
                    if (Schema::hasColumn('student_profiles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
