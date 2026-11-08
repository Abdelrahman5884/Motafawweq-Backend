<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_submission_answers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_submission_id')
                ->constrained('exam_submissions')
                ->cascadeOnDelete();
            $table->foreignId('exam_question_id')
                ->constrained('exam_questions')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('selected_index')->nullable();
            $table->boolean('selected_bool')->nullable();
            $table->text('essay_answer_text')->nullable();

            $table->decimal('points_awarded', 5, 2)->default(0.00);
            $table->boolean('is_correct')->nullable();
            $table->text('teacher_feedback')->nullable();

            $table->timestamps();

            $table->unique(['exam_submission_id', 'exam_question_id'], 'sub_ans_sub_q_unique');
            $table->index('exam_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_submission_answers');
    }
};
