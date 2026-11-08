<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Academic Identity & Curriculum Mapping
            $table->string('title_ar', 200);
            $table->string('title_en', 200)->nullable();
            $table->string('stage_id', 20)->default('sec');
            $table->string('stage_name_ar', 100)->default('المرحلة الثانوية');
            $table->string('grade_ar', 100)->default('الصف الثالث الثانوي');
            $table->string('subject_ar', 120)->default('الأحياء');
            $table->string('subject_en', 120)->nullable();

            // Pricing & Description
            $table->decimal('price_egp', 10, 2)->default(350.00);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('cover_url', 500)->nullable();

            // Metrics & Status
            $table->string('hours_total', 60)->default('20 ساعة');
            $table->unsignedInteger('lessons_count')->default(1);
            $table->unsignedInteger('students_count')->default(0);
            $table->unsignedTinyInteger('completion_rate')->default(100);
            $table->decimal('revenue_egp', 12, 2)->default(0.00);
            $table->string('status', 20)->default('published');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status', 'deleted_at']);
            $table->index(['teacher_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};

