<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('study_group_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('schedule_slot_id')
                ->nullable()
                ->constrained('group_schedule_slots')
                ->nullOnDelete();

            $table->date('session_date');
            $table->string('title_ar', 150)->nullable();
            $table->string('status', 20)->default('open'); // open, closed
            $table->text('notes')->nullable();

            // Aggregated Session Metrics
            $table->unsignedSmallInteger('total_enrolled')->default(0);
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->decimal('attendance_rate', 5, 2)->default(0.00);

            $table->timestamps();

            $table->unique(['study_group_id', 'session_date']);
            $table->index(['teacher_id', 'session_date']);
            $table->index('study_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_attendance_sessions');
    }
};
