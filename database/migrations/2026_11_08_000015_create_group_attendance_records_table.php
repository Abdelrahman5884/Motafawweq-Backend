<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_session_id')
                ->constrained('group_attendance_sessions')
                ->cascadeOnDelete();
            $table->foreignId('study_group_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
            $table->foreignId('group_enrollment_id')
                ->constrained('group_enrollments')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('status', 20)->default('present'); // present, absent, late, excused
            $table->string('recorded_method', 30)->default('manual_toggle'); // qr_scan, barcode_input, manual_toggle, passcode
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->unique(['attendance_session_id', 'group_enrollment_id'], 'grp_att_sess_enroll_unique');
            $table->index(['study_group_id', 'student_id']);
            $table->index(['group_enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_attendance_records');
    }
};
