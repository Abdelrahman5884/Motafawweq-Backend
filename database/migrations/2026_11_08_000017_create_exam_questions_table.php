<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_id')
                ->constrained('exams')
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('question_number');
            $table->string('type', 20)->default('mcq'); // mcq, true_false, essay
            $table->unsignedSmallInteger('points')->default(5);
            $table->string('difficulty', 30)->default('متوسط');

            $table->text('question_ar');
            $table->text('question_en')->nullable();

            // MCQ Options: list of strings
            $table->json('options_ar')->nullable();
            $table->unsignedTinyInteger('correct_index')->nullable(); // 0..3

            // True / False: boolean
            $table->boolean('correct_bool')->nullable();

            // Essay: model answer & rubric criteria
            $table->text('model_answer_ar')->nullable();
            $table->json('rubric_points_ar')->nullable();

            $table->text('explanation_ar')->nullable();

            $table->timestamps();

            $table->unique(['exam_id', 'question_number'], 'exam_questions_num_unique');
            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};

