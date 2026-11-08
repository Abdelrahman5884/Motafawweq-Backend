<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_concept_graphs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')
                ->unique()
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->json('tree_data');
            $table->string('source', 30)->default('manual'); // 'manual' | 'ai'

            $table->timestamps();

            $table->index(['teacher_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_concept_graphs');
    }
};

