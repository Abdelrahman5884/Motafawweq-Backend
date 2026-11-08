<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_schedule_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('day_of_week', 20); // saturday, sunday, monday, etc.
            $table->text('note_text');
            $table->string('note_time', 10)->default('16:00');
            $table->string('priority', 20)->default('medium'); // high, medium, normal
            $table->boolean('is_done')->default(false);

            $table->timestamps();

            $table->index(['teacher_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_schedule_notes');
    }
};

