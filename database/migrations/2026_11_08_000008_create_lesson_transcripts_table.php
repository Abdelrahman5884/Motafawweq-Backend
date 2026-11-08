<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_transcripts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('lesson_id')
                ->constrained('lessons')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('speaker', 120)->default('المعلم');
            $table->unsignedInteger('start_seconds')->default(0);
            $table->string('timestamp_label', 20)->default('00:00');
            $table->text('text_ar');
            $table->text('text_en')->nullable();
            $table->unsignedSmallInteger('display_order')->default(1);

            $table->timestamps();

            $table->index(['lesson_id', 'start_seconds']);
            $table->index(['teacher_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_transcripts');
    }
};

