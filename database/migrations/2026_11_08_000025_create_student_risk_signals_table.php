<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_risk_signals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('study_group_id')
                ->nullable()
                ->constrained('study_groups')
                ->nullOnDelete();

            $table->string('risk_level', 20)->default('medium'); // high, medium, low
            $table->text('risk_reasons_ar');
            $table->decimal('attendance_drop_pct', 5, 2)->default(0.00);
            $table->decimal('quiz_score_drop_pct', 5, 2)->default(0.00);
            $table->unsignedTinyInteger('attendance_pct')->default(0);
            $table->decimal('avg_exam_score', 5, 2)->default(0.00);
            $table->timestamp('last_active_at')->nullable();
            $table->string('status', 20)->default('detected'); // detected, notified, resolved

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['teacher_id', 'student_id'], 'trk_sig_tch_std_unique');
            $table->index(['teacher_id', 'status']);
            $table->index(['teacher_id', 'risk_level']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_risk_signals');
    }
};

