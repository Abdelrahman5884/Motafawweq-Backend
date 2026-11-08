<?php

use App\Enums\InvoiceStatus;
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
        Schema::create('teacher_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 50)->unique()->index();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('teacher_subscriptions')->nullOnDelete();

            $table->string('description_ar', 255);
            $table->string('description_en', 255)->nullable();
            $table->decimal('base_amount_egp', 10, 2);
            $table->decimal('discount_amount_egp', 10, 2)->default(0.00);
            $table->decimal('final_amount_egp', 10, 2);

            $table->string('payment_method', 30)->default(PaymentMethod::VODAFONE->value);
            $table->string('status', 20)->default(InvoiceStatus::PAID->value)->index();
            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->index(['teacher_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_invoices');
    }
};

