<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_league_id')
                ->constrained('course_leagues')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unsignedInteger('rank')->default(1);
            $table->unsignedInteger('previous_rank')->nullable();
            $table->unsignedInteger('xp_points')->default(0);
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->string('badge_title_ar', 100)->nullable();
            $table->string('tier', 30)->default('Bronze'); // Diamond, Gold, Silver, Bronze
            $table->string('movement', 10)->default('same'); // up, down, same
            $table->string('school_ar', 200)->nullable();
            $table->string('group_ar', 150)->nullable();

            $table->unsignedSmallInteger('perfect_exams_count')->default(0);
            $table->unsignedSmallInteger('exams_solved_count')->default(0);
            $table->unsignedTinyInteger('homework_rate')->default(0);
            $table->unsignedTinyInteger('attendance_rate')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_league_id', 'student_id'], 'lg_part_lg_std_unique');
            $table->index(['course_league_id', 'rank']);
            $table->index(['course_league_id', 'tier']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_participants');
    }
};

