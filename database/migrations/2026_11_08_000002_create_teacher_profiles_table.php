<?php

use App\Enums\TeacherPlanTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Academic & personal identity
            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->string('title_ar', 200)->nullable();
            $table->string('title_en', 200)->nullable();
            $table->string('subject_ar', 120)->nullable();
            $table->string('subject_en', 120)->nullable();
            $table->text('bio_ar')->nullable();
            $table->text('bio_en')->nullable();
            $table->string('whatsapp_phone', 30)->nullable();

            // Selected curriculum grades (JSON array of Arabic grade names)
            $table->json('stages_taught')->nullable();

            // Protected platform tier & verification flags (not mass-assignable)
            $table->string('plan_tier', 20)->default(TeacherPlanTier::PLUS->value)->index();
            $table->boolean('is_verified_educator')->default(true);

            // Security & notification preferences
            $table->boolean('two_factor_enabled')->default(true);
            $table->boolean('notify_whatsapp_intervention')->default(true);
            $table->boolean('notify_new_enrollment')->default(true);
            $table->boolean('notify_exam_submission')->default(true);
            $table->boolean('auto_watermark_enabled')->default(true);
            $table->string('preferred_theme', 10)->default('light');
            $table->string('preferred_lang', 5)->default('ar');

            // Public reputation metrics (system-calculated, not mass-assignable)
            $table->decimal('rating', 3, 2)->default(5.00);
            $table->unsignedInteger('reviews_count')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};

