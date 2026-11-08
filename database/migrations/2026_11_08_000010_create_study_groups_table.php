<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('subject_ar', 120);
            $table->string('subject_en', 120)->nullable();
            $table->string('grade_ar', 100);
            $table->string('grade_en', 100)->nullable();

            $table->string('center_name', 150);
            $table->string('hall_name', 100)->nullable();
            $table->string('schedule_ar', 200)->nullable();
            $table->string('schedule_en', 200)->nullable();

            $table->unsignedSmallInteger('max_students')->default(50);
            $table->decimal('price_egp', 10, 2)->default(0.00);
            $table->string('join_code', 32)->unique();
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status', 'deleted_at']);
            $table->index('join_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_groups');
    }
};

