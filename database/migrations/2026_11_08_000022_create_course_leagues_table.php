<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_leagues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->constrained('courses')
                ->cascadeOnDelete();
            $table->foreignId('study_group_id')
                ->nullable()
                ->constrained('study_groups')
                ->nullOnDelete();

            $table->string('title_ar', 250);
            $table->string('course_name_ar', 200)->nullable();
            $table->string('group_name_ar', 150)->default('جميع المجموعات (دوري موحد)');
            $table->string('duration_type', 30)->default('monthly'); // weekly, monthly, half_year, full_year
            $table->unsignedSmallInteger('duration_days')->default(30);
            $table->boolean('is_active')->default(true);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('total_competitors')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'is_active', 'deleted_at']);
            $table->index('course_id');
            $table->index('study_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_leagues');
    }
};

