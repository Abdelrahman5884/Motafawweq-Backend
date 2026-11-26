<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('lesson_id')
                ->nullable()
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('converted_lecture_id')
                ->nullable()
                ->constrained('student_converted_lectures')
                ->cascadeOnDelete();

            $table->string('task_type', 40)->default('speech_to_text');
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage', 50)->default('queued');

            $table->string('remote_job_id', 100)->nullable()->index();
            $table->string('model_name', 80)->nullable();
            $table->string('requested_language', 16)->nullable();
            $table->string('detected_language', 16)->nullable();
            $table->decimal('language_probability', 5, 4)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('segments_count')->default(0);

            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['lesson_id', 'task_type', 'status']);
            $table->index(['converted_lecture_id', 'task_type', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_processing_jobs');
    }
};
