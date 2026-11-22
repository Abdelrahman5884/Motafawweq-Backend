<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_ministry_unit_progress', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('curriculum_code', 64);
            $table->unsignedSmallInteger('unit_index');
            $table->boolean('is_completed')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'curriculum_code', 'unit_index'], 'smup_student_curr_unit_unique');
            $table->index(['student_id', 'curriculum_code'], 'smup_student_curr_idx');
        });

        Schema::create('student_ministry_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('exam_code', 64);
            $table->string('subject_ar', 100);
            $table->string('grade_ar', 100);
            $table->string('title_ar', 255);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('answers_json')->nullable();
            $table->decimal('score', 6, 2)->default(0);
            $table->unsignedSmallInteger('full_mark')->default(20);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('is_passed')->nullable();
            $table->boolean('is_full_mark')->default(false);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('wrong_count')->default(0);
            $table->unsignedSmallInteger('unanswered_count')->default(0);
            $table->unsignedSmallInteger('tab_switches_count')->default(0);
            $table->unsignedSmallInteger('xp_awarded')->default(0);
            $table->string('status', 20)->default('in_progress');
            $table->timestamps();

            $table->index(['student_id', 'exam_code', 'status'], 'smea_student_exam_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_ministry_exam_attempts');
        Schema::dropIfExists('student_ministry_unit_progress');
    }
};
