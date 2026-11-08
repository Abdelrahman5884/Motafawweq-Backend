<?php

use App\Enums\DirectPaymentStatus;
use App\Enums\PaymentMethod;
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
        Schema::create('student_direct_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_num', 50)->unique()->index();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('study_group_id')->nullable()->constrained('study_groups')->nullOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained('teacher_payout_accounts')->nullOnDelete();

            $table->decimal('amount_egp', 10, 2);
            $table->string('payment_method', 30)->default(PaymentMethod::VODAFONE->value);
            $table->string('student_name_ar', 150);
            $table->string('student_name_en', 150)->nullable();
            $table->string('student_phone', 30)->nullable();
            $table->string('course_name_ar', 200);
            $table->string('course_name_en', 200)->nullable();

            $table->string('receipt_image_path', 255)->nullable();
            $table->string('status', 20)->default(DirectPaymentStatus::PENDING->value)->index();
            $table->dateTime('paid_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_direct_payments');
    }
};

