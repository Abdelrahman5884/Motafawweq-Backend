<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('study_group_id')
                ->constrained('study_groups')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('day_of_week', 20); // saturday, sunday, monday, etc.
            $table->string('start_time', 10);  // 16:00
            $table->string('end_time', 10);    // 18:00
            $table->string('hall_name', 100)->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'day_of_week']);
            $table->index('study_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_schedule_slots');
    }
};

