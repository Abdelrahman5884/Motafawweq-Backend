<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parent_profiles')) {
            Schema::create('parent_profiles', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('emergency_phone', 30)->nullable();
                $table->boolean('notify_tuition_renewal')->default(true);
                $table->boolean('notify_achievements_certificates')->default(true);
                $table->string('preferred_lang', 10)->default('ar');
                $table->string('preferred_theme', 20)->default('system');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('parent_inquiries')) {
            Schema::create('parent_inquiries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('parent_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('center_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('recipient_type', 20)->default('center');
                $table->string('subject', 190);
                $table->text('message');
                $table->string('status', 30)->default('open');
                $table->text('reply_text')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamps();

                $table->index(['parent_id', 'created_at']);
                $table->index(['student_id', 'status']);
                $table->index(['center_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_inquiries');
        Schema::dropIfExists('parent_profiles');
    }
};
