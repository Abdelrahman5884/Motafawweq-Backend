<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('student_profiles')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('student_profiles', 'track_ar')) {
                    $table->string('track_ar', 100)->nullable()->after('grade_ar');
                }
                if (! Schema::hasColumn('student_profiles', 'target_goal')) {
                    $table->string('target_goal', 255)->nullable()->after('nickname');
                }
                if (! Schema::hasColumn('student_profiles', 'center_name')) {
                    $table->string('center_name', 150)->nullable()->after('school_name');
                }
            });
        }

        if (! Schema::hasTable('student_schedule_notes')) {
            Schema::create('student_schedule_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->string('day_of_week', 20)->index();
                $table->string('text', 500);
                $table->string('time', 20)->nullable();
                $table->string('priority', 20)->default('medium');
                $table->boolean('is_done')->default(false);
                $table->timestamps();

                $table->index(['student_id', 'day_of_week']);
            });
        }

        if (! Schema::hasTable('student_converted_lectures')) {
            Schema::create('student_converted_lectures', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
                $table->string('title_ar', 255);
                $table->string('subject_ar', 120)->nullable();
                $table->string('grade_ar', 120)->nullable();
                $table->string('file_name', 255)->nullable();
                $table->string('file_size', 60)->nullable();
                $table->string('duration_label', 40)->nullable();
                $table->text('summary_ar')->nullable();
                $table->json('outputs')->nullable();
                $table->json('transcript')->nullable();
                $table->json('topics')->nullable();
                $table->json('mindmap')->nullable();
                $table->json('quiz_questions')->nullable();
                $table->unsignedSmallInteger('questions_count')->default(0);
                $table->unsignedSmallInteger('concepts_count')->default(0);
                $table->timestamps();

                $table->index(['student_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_converted_lectures');
        Schema::dropIfExists('student_schedule_notes');

        if (Schema::hasTable('student_profiles')) {
            Schema::table('student_profiles', function (Blueprint $table) {
                foreach (['track_ar', 'target_goal', 'center_name'] as $col) {
                    if (Schema::hasColumn('student_profiles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
