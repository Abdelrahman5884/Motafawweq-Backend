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
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('plan_tier', 20)->default(TeacherPlanTier::FREE->value)->change();
        });

        Schema::table('teacher_subscriptions', function (Blueprint $table) {
            $table->string('plan_tier', 20)->default(TeacherPlanTier::FREE->value)->change();
            $table->dateTime('renews_at')->nullable()->change();
            if (! Schema::hasColumn('teacher_subscriptions', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable()->after('renews_at');
            }
        });

        Schema::table('teacher_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('teacher_invoices', 'coupon_code')) {
                $table->string('coupon_code', 50)->nullable()->after('final_amount_egp');
            }
            if (! Schema::hasColumn('teacher_invoices', 'sender_reference')) {
                $table->string('sender_reference', 255)->nullable()->after('payment_method');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teacher_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('teacher_invoices', 'sender_reference')) {
                $table->dropColumn('sender_reference');
            }
            if (Schema::hasColumn('teacher_invoices', 'coupon_code')) {
                $table->dropColumn('coupon_code');
            }
        });

        Schema::table('teacher_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('teacher_subscriptions', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            $table->string('plan_tier', 20)->default(TeacherPlanTier::PLUS->value)->change();
        });

        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('plan_tier', 20)->default(TeacherPlanTier::PLUS->value)->change();
        });
    }
};
