<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('homework_id')
                ->constrained('homeworks')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('location_ar', 150)->nullable();
            $table->string('submission_type', 20)->default('file'); // pdf, image, text
            $table->string('attachment_name', 255)->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->longText('solution_text')->nullable();

            $table->string('status', 20)->default('pending'); // pending, graded, rejected
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedSmallInteger('max_score')->default(20);
            $table->text('feedback_ar')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('graded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['homework_id', 'student_id'], 'hw_sub_hw_std_unique');
            $table->index(['homework_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_submissions');
    }
};

