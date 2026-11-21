<?php

use App\Enums\BillingPeriod;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentPlanTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();

            $table->string('plan_tier', 20)->default(StudentPlanTier::FREE->value);
            $table->string('plan_name_ar', 150);
            $table->string('plan_name_en', 150)->nullable();
            $table->string('billing_period', 20)->default(BillingPeriod::MONTHLY->value);
            $table->decimal('amount_egp', 10, 2)->default(0);

            $table->dateTime('starts_at');
            $table->dateTime('renews_at')->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        Schema::create('student_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 50)->unique()->index();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('student_subscriptions')->nullOnDelete();

            $table->string('description_ar', 255);
            $table->string('description_en', 255)->nullable();
            $table->decimal('base_amount_egp', 10, 2);
            $table->decimal('discount_amount_egp', 10, 2)->default(0);
            $table->decimal('final_amount_egp', 10, 2);
            $table->string('coupon_code', 50)->nullable();

            $table->string('payment_method', 30)->default(PaymentMethod::VODAFONE->value);
            $table->string('sender_reference', 150)->nullable();
            $table->string('status', 20)->default(InvoiceStatus::PAID->value)->index();
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_invoices');
        Schema::dropIfExists('student_subscriptions');
    }
};
