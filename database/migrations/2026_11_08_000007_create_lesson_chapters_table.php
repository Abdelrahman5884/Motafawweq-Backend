<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_chapters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('title_ar', 220);
            $table->string('title_en', 220)->nullable();
            $table->unsignedInteger('start_seconds')->default(0);
            $table->string('timestamp_label', 20)->default('00:00');
            $table->unsignedSmallInteger('display_order')->default(1);

            $table->timestamps();

            $table->index(['lesson_id', 'start_seconds']);
            $table->index(['teacher_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_chapters');
    }
};

