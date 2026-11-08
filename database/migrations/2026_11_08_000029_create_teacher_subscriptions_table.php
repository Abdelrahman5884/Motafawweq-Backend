<?php

use App\Enums\BillingPeriod;
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
        Schema::create('teacher_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();

            $table->string('plan_tier', 20)->default(TeacherPlanTier::PLUS->value);
            $table->string('plan_name_ar', 150);
            $table->string('plan_name_en', 150)->nullable();
            $table->string('billing_period', 20)->default(BillingPeriod::MONTHLY->value);
            $table->decimal('amount_egp', 10, 2);

            $table->dateTime('starts_at');
            $table->dateTime('renews_at');
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();

            $table->index(['teacher_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_subscriptions');
    }
};

