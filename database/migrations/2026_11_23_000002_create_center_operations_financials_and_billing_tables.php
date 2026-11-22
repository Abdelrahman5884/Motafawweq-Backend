<?php

use App\Enums\BillingPeriod;
use App\Enums\CenterExpenseCategory;
use App\Enums\CenterPlanTier;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();

            $table->string('receipt_number', 50)->unique();
            $table->string('title_ar', 220);
            $table->string('title_en', 220)->nullable();
            $table->string('category', 30)->default(CenterExpenseCategory::OTHER->value);
            $table->decimal('amount_egp', 12, 2);
            $table->date('expense_date');
            $table->string('status', 20)->default('paid')->index();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'expense_date', 'deleted_at']);
            $table->index(['center_id', 'branch_id', 'category']);
        });

        Schema::create('center_teacher_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('center_teacher_id')->constrained('center_teachers')->cascadeOnDelete();

            $table->string('period_key', 20); // e.g. 2026-11
            $table->unsignedInteger('groups_count')->default(0);
            $table->unsignedInteger('paying_students_count')->default(0);
            $table->decimal('gross_collected_egp', 12, 2)->default(0.00);
            $table->decimal('settlement_rate', 5, 4)->default(0.7500);
            $table->decimal('teacher_share_egp', 12, 2)->default(0.00);
            $table->decimal('center_share_egp', 12, 2)->default(0.00);

            $table->string('status', 20)->default('pending')->index(); // pending | approved | paid
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reference_note', 255)->nullable();

            $table->timestamps();

            $table->unique(['center_id', 'center_teacher_id', 'period_key'], 'center_teacher_period_unique');
            $table->index(['center_id', 'period_key', 'status']);
        });

        Schema::create('center_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('center_staff')->nullOnDelete();

            $table->text('title_ar');
            $table->string('assignee_name', 150)->nullable();
            $table->string('deadline_label', 120)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->boolean('is_completed')->default(false)->index();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'is_completed', 'deleted_at']);
        });

        Schema::create('center_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('center_branches')->nullOnDelete();

            $table->string('title_ar', 200);
            $table->text('content_ar');
            $table->string('author_name', 150);
            $table->string('branch_label', 150)->default('جميع الفروع');
            $table->timestamp('published_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['center_id', 'published_at', 'deleted_at']);
        });

        Schema::create('center_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('snapshot_code', 64)->unique();
            $table->string('status', 20)->default('completed');
            $table->string('checksum_sha256', 64);
            $table->json('records_summary')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamp('completed_at');

            $table->timestamps();

            $table->index(['center_id', 'completed_at']);
        });

        Schema::create('center_absence_alerts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('study_group_id')->constrained('study_groups')->cascadeOnDelete();
            $table->foreignId('group_enrollment_id')->constrained('group_enrollments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->date('session_date');
            $table->string('parent_phone', 40)->nullable();
            $table->string('channel', 20)->default('whatsapp'); // whatsapp | sms | in_app
            $table->text('message_ar');
            $table->string('status', 20)->default('sent');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');

            $table->timestamps();

            $table->index(['center_id', 'session_date']);
            $table->index(['study_group_id', 'group_enrollment_id', 'session_date'], 'center_absence_alert_lookup');
        });

        Schema::create('center_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();

            $table->string('plan_tier', 20)->default(CenterPlanTier::FREE->value);
            $table->string('plan_name_ar', 150);
            $table->string('plan_name_en', 150)->nullable();
            $table->string('billing_period', 20)->default(BillingPeriod::MONTHLY->value);
            $table->decimal('amount_egp', 10, 2)->default(0.00);

            $table->dateTime('starts_at');
            $table->dateTime('renews_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();

            $table->index(['center_id', 'status']);
        });

        Schema::create('center_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 50)->unique()->index();
            $table->foreignId('center_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('center_subscriptions')->nullOnDelete();

            $table->string('description_ar', 255);
            $table->string('description_en', 255)->nullable();
            $table->decimal('base_amount_egp', 10, 2);
            $table->decimal('discount_amount_egp', 10, 2)->default(0.00);
            $table->decimal('final_amount_egp', 10, 2);
            $table->string('coupon_code', 50)->nullable();

            $table->string('payment_method', 30)->default(PaymentMethod::INSTAPAY->value);
            $table->string('sender_reference', 255)->nullable();
            $table->string('status', 20)->default(InvoiceStatus::PAID->value)->index();
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->index(['center_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_invoices');
        Schema::dropIfExists('center_subscriptions');
        Schema::dropIfExists('center_absence_alerts');
        Schema::dropIfExists('center_backups');
        Schema::dropIfExists('center_announcements');
        Schema::dropIfExists('center_tasks');
        Schema::dropIfExists('center_teacher_settlements');
        Schema::dropIfExists('center_expenses');
    }
};
