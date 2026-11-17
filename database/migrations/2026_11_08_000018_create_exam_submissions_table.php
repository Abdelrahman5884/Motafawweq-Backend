<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->decimal('score', 6, 2)->default(0.00);
            $table->unsignedSmallInteger('full_mark')->default(50);
            $table->decimal('percentage', 5, 2)->default(0.00);
            $table->boolean('is_passed')->default(false);
            $table->boolean('is_full_mark')->default(false);

            $table->string('status', 20)->default('in_progress'); // in_progress, submitted, graded
            $table->text('teacher_feedback')->nullable();

            $table->foreignId('graded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->index(['exam_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index(['exam_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_submissions');
    }
};
