<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->string('course_title_ar', 200)->nullable();
            $table->string('stage', 50)->default('sec');
            $table->string('grade_ar', 100)->default('الصف الثالث الثانوي');

            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('full_mark')->default(50);
            $table->unsignedSmallInteger('pass_score')->default(25);
            $table->unsignedSmallInteger('questions_count')->default(0);

            $table->string('status', 20)->default('active'); // active, closed, scheduled
            $table->boolean('show_results')->default(true);
            $table->boolean('is_bubble_sheet')->default(false);

            $table->date('due_date')->nullable();
            $table->timestamp('starts_at')->nullable();

            // Computed Performance Metrics
            $table->decimal('avg_score', 5, 2)->default(0.00);
            $table->decimal('pass_rate', 5, 2)->default(0.00);
            $table->unsignedInteger('top_score_count')->default(0);
            $table->unsignedInteger('submissions_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status', 'deleted_at']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};

