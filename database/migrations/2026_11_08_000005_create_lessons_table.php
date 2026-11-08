<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            // Lesson Metadata & Curriculum Target
            $table->string('title_ar', 220);
            $table->string('title_en', 220)->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('subject_ar', 120)->default('الأحياء');
            $table->string('grade_ar', 100)->default('الصف الثالث الثانوي');
            $table->string('target_group_code', 100)->nullable();

            // Studio Media Configuration (Mic / Audio / Video / Embed)
            $table->string('media_mode', 20)->default('video');
            $table->string('media_disk', 40)->default('local');
            $table->string('media_path', 500)->nullable();
            $table->string('media_original_name', 255)->nullable();
            $table->string('media_mime_type', 100)->nullable();
            $table->unsignedBigInteger('media_size_bytes')->nullable();
            $table->string('embed_url', 500)->nullable();
            $table->boolean('is_youtube')->default(false);
            $table->unsignedInteger('duration_seconds')->default(2700);
            $table->string('duration_label', 40)->default('45:00');

            // Deferred AI Configuration (ready for future AI processing stage)
            $table->json('ai_features_requested')->nullable();

            // Status & Publishing
            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'course_id', 'status']);
            $table->index(['teacher_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};

