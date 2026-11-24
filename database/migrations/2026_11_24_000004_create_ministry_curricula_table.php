<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ministry_curricula', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 80)->unique();
            $table->string('title_ar', 255);
            $table->string('title_en', 255)->nullable();
            $table->string('subject_ar', 120);
            $table->string('subject_en', 120)->nullable();
            $table->string('grade_ar', 100)->index();
            $table->string('grade_subtitle_ar', 180)->nullable();
            $table->string('grade_subtitle_en', 180)->nullable();
            $table->string('stage', 40)->index();
            $table->string('term', 24)->default('full_year');
            $table->json('tracks')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('textbook_url', 500)->nullable();
            $table->string('exam_code', 80)->nullable()->index();
            $table->json('units')->nullable();
            $table->boolean('is_published')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage', 'grade_ar', 'is_published'], 'mc_stage_grade_pub_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ministry_curricula');
    }
};
