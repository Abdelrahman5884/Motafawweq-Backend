<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('study_group_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status', 20)->default('pending'); // pending, enrolled, rejected, removed
            $table->string('join_method', 30)->default('join_code'); // qr_scan, join_code, teacher_invite, direct_enrollment
            $table->string('student_barcode', 64)->unique()->nullable();
            $table->string('student_passcode', 10)->nullable();

            // Contact details (can override or cache student/parent info)
            $table->string('student_name_override', 150)->nullable();
            $table->string('student_phone_override', 30)->nullable();
            $table->string('parent_name_ar', 150)->nullable();
            $table->string('parent_phone', 30)->nullable();

            // Academic & Attendance Metrics
            $table->unsignedTinyInteger('attendance_rate')->default(100);
            $table->unsignedSmallInteger('attended_sessions')->default(0);
            $table->unsignedSmallInteger('total_sessions')->default(0);
            $table->unsignedTinyInteger('avg_quiz_score')->default(0);
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->string('academic_status', 30)->default('Good');

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['study_group_id', 'student_id']);
            $table->index(['study_group_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index('student_barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_enrollments');
    }
};

