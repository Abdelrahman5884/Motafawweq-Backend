<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homeworks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();
            $table->string('course_title_ar', 200)->nullable();
            $table->foreignId('lesson_id')
                ->nullable()
                ->constrained('lessons')
                ->nullOnDelete();
            $table->string('lesson_title_ar', 200)->nullable();

            $table->string('title_ar', 250);
            $table->string('target_location_id', 50)->default('all');
            $table->string('target_location_ar', 150)->default('جميع الأماكن (كل السناتر + الأونلاين)');
            $table->string('type', 20)->default('file'); // file, text, image
            $table->string('file_type', 20)->nullable(); // pdf, image
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->longText('text_content')->nullable();

            $table->date('due_date')->nullable();
            $table->string('due_time', 10)->default('23:59');
            $table->unsignedSmallInteger('max_grade')->default(20);

            // Submission & grading counters
            $table->unsignedInteger('total_assigned')->default(0);
            $table->unsignedInteger('submitted_count')->default(0);
            $table->unsignedInteger('graded_count')->default(0);
            $table->unsignedInteger('pending_grading')->default(0);
            $table->decimal('avg_grade', 5, 2)->default(0.00);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'deleted_at']);
            $table->index('course_id');
            $table->index('lesson_id');
            $table->index('target_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homeworks');
    }
};

