<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Harden exam_submissions with server-side expiry, anti-cheat telemetry, and unique constraint
        Schema::table('exam_submissions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('started_at');
            $table->unsignedSmallInteger('tab_switches_count')->default(0)->after('is_full_mark');
            $table->string('ip_address', 45)->nullable()->after('tab_switches_count');
            $table->unique(['exam_id', 'student_id'], 'exam_sub_exam_student_unique');
        });

        // 2. Student Profiles, Study Goals, Preferences & Privacy Settings
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('stage', 50)->default('high');
            $table->string('grade_ar', 100)->default('الصف الثالث الثانوي');
            $table->string('governorate', 100)->nullable();
            $table->string('school_name', 150)->nullable();
            $table->string('parent_name', 150)->nullable();
            $table->string('parent_phone', 30)->nullable();

            // Study Goals
            $table->unsignedSmallInteger('daily_study_goal_minutes')->default(120);
            $table->unsignedSmallInteger('weekly_exams_goal')->default(3);
            $table->unsignedSmallInteger('target_score_percent')->default(90);

            // Privacy Controls
            $table->boolean('show_on_leaderboard')->default(true);
            $table->string('display_name_mode', 20)->default('full'); // full, initials, nickname
            $table->string('nickname', 60)->nullable();

            // Preferences & Notification Settings
            $table->json('preferences')->nullable();
            $table->json('notifications')->nullable();

            $table->timestamps();
        });

        // 3. Student Personal Study Sessions (Weekly Planner with Overlap Protection)
        Schema::create('student_study_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('day_of_week', 20);
            $table->string('subject_ar', 120);
            $table->string('title_ar', 200);
            $table->string('start_time', 10); // HH:MM
            $table->string('end_time', 10);   // HH:MM
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->boolean('is_completed')->default(false);
            $table->string('color', 20)->default('#1588C7');
            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['student_id', 'day_of_week']);
        });

        // 4. Student Lesson Progress & Bookmarks (Server-Verified Anti-Skip Heartbeat)
        Schema::create('student_lesson_progresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();

            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->default(1800);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_bookmarked')->default(false);
            $table->timestamp('last_heartbeat_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'lesson_id'], 'std_lesson_prog_unique');
            $table->index(['student_id', 'course_id']);
            $table->index(['student_id', 'is_bookmarked']);
        });

        // 5. Student Private Timestamped Lesson Notes
        Schema::create('student_lesson_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();

            $table->unsignedInteger('timestamp_seconds')->default(0);
            $table->text('content');

            $table->timestamps();

            $table->index(['student_id', 'lesson_id']);
        });

        // 6. Immutable Append-Only Student XP Ledger
        Schema::create('student_xp_ledgers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('source_type', 50); // lesson_completed, exam_submitted, homework_submitted, streak_bonus
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('points');
            $table->string('reason_ar', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['student_id', 'source_type', 'source_id'], 'std_xp_ledger_source_unique');
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_xp_ledgers');
        Schema::dropIfExists('student_lesson_notes');
        Schema::dropIfExists('student_lesson_progresses');
        Schema::dropIfExists('student_study_sessions');
        Schema::dropIfExists('student_profiles');

        Schema::table('exam_submissions', function (Blueprint $table) {
            $table->dropUnique('exam_sub_exam_student_unique');
            $table->dropColumn(['expires_at', 'tab_switches_count', 'ip_address']);
        });
    }
};
