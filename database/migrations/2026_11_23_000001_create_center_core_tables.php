<?php

use App\Enums\CenterPlanTier;
use App\Enums\CenterRefundPolicy;
use App\Enums\CenterStaffRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Identity & official contacts
            $table->string('name_ar', 180);
            $table->string('name_en', 180)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address_ar', 255)->nullable();
            $table->string('address_en', 255)->nullable();
            $table->string('working_hours', 120)->default('08:00 ص - 10:00 م');
            $table->string('logo_url', 500)->nullable();
            $table->string('custom_domain', 180)->nullable();

            // Protected platform tier & verification flags (not mass-assignable)
            $table->string('plan_tier', 20)->default(CenterPlanTier::FREE->value)->index();
            $table->boolean('is_verified_center')->default(true);

            // Operational & financial policies
            $table->unsignedSmallInteger('late_margin_minutes')->default(15);
            $table->json('payment_methods')->nullable();
            $table->unsignedTinyInteger('sibling_discount_percent')->default(10);
            $table->boolean('mandatory_cash_close')->default(true);
            $table->string('refund_policy', 20)->default(CenterRefundPolicy::CREDIT->value);
            $table->decimal('default_teacher_settlement_rate', 5, 4)->default(0.7500);

            // Custom KPI targets
            $table->unsignedTinyInteger('target_attendance_pct')->default(95);
            $table->decimal('target_monthly_revenue_egp', 12, 2)->default(300000.00);
            $table->unsignedTinyInteger('target_room_utilization_pct')->default(85);

            // UI preferences & backup snapshot metadata
            $table->string('preferred_theme', 10)->default('light');
            $table->string('preferred_lang', 5)->default('ar');
            $table->timestamp('last_backup_at')->nullable();
            $table->string('last_backup_checksum', 64)->nullable();

            $table->timestamps();
        });

        Schema::create('center_branches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();

            $table->string('name_ar', 180);
            $table->string('name_en', 180)->nullable();
            $table->string('address_ar', 255)->nullable();
            $table->string('address_en', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('manager_name_ar', 150)->nullable();
            $table->string('manager_name_en', 150)->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'is_active', 'deleted_at']);
        });

        Schema::create('center_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();

            $table->string('name_ar', 180);
            $table->string('name_en', 180)->nullable();
            $table->string('floor', 80)->nullable();
            $table->unsignedSmallInteger('capacity')->default(40);
            $table->json('equipment')->nullable();
            $table->boolean('is_available')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'branch_id', 'deleted_at']);
        });

        Schema::create('center_teachers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();
            $table->foreignId('teacher_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->string('subject_ar', 150);
            $table->string('subject_en', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255);
            $table->decimal('settlement_rate', 5, 4)->default(0.7500);
            $table->string('status', 20)->default('active')->index();

            $table->string('invite_token_hash', 64)->nullable();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'branch_id', 'deleted_at']);
            $table->index(['center_id', 'email']);
            $table->index(['center_id', 'teacher_user_id']);
        });

        Schema::create('center_staff', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('role', 30)->default(CenterStaffRole::RECEPTIONIST->value);
            $table->string('role_ar', 120)->nullable();
            $table->decimal('salary_egp', 10, 2)->default(0.00);
            $table->json('permissions')->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'branch_id', 'deleted_at']);
        });

        Schema::create('center_connected_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();

            $table->string('connected_role', 20); // teacher | student
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();

            $table->unique(['center_id', 'user_id']);
            $table->index(['center_id', 'connected_role']);
        });

        Schema::create('center_queue_completions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('study_group_id')->constrained('study_groups')->cascadeOnDelete();
            $table->foreignId('schedule_slot_id')->nullable()->constrained('group_schedule_slots')->cascadeOnDelete();

            $table->date('queue_date');
            $table->string('day_of_week', 20);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at');

            $table->timestamps();

            $table->unique(['center_id', 'study_group_id', 'schedule_slot_id', 'queue_date'], 'center_queue_unique');
            $table->index(['center_id', 'queue_date', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_queue_completions');
        Schema::dropIfExists('center_connected_users');
        Schema::dropIfExists('center_staff');
        Schema::dropIfExists('center_teachers');
        Schema::dropIfExists('center_rooms');
        Schema::dropIfExists('center_branches');
        Schema::dropIfExists('center_profiles');
    }
};
