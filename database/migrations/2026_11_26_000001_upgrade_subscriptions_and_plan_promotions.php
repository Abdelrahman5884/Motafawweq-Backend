<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add promotional pricing (strikethrough compare-at prices, discount %, temporary free promo) to plans
        Schema::table('platform_subscription_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_subscription_plans', 'original_price_egp')) {
                $table->decimal('original_price_egp', 10, 2)->nullable()->after('price_egp');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'original_term_price_egp')) {
                $table->decimal('original_term_price_egp', 10, 2)->nullable()->after('term_price_egp');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'original_annual_price_egp')) {
                $table->decimal('original_annual_price_egp', 10, 2)->nullable()->after('annual_price_egp');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'discount_percent')) {
                $table->decimal('discount_percent', 5, 2)->nullable()->after('original_annual_price_egp');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'free_promo_enabled')) {
                $table->boolean('free_promo_enabled')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'free_promo_until')) {
                $table->timestamp('free_promo_until')->nullable()->after('free_promo_enabled');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'free_promo_label_ar')) {
                $table->string('free_promo_label_ar', 140)->nullable()->after('free_promo_until');
            }
            if (! Schema::hasColumn('platform_subscription_plans', 'free_promo_label_en')) {
                $table->string('free_promo_label_en', 140)->nullable()->after('free_promo_label_ar');
            }
        });

        // 2. Add approval, receipt, transaction reference, and cancellation fields across all 3 subscription tables
        foreach (['student_subscriptions', 'teacher_subscriptions', 'center_subscriptions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'cancelled_at')) {
                    $table->dateTime('cancelled_at')->nullable()->after('renews_at');
                }
                if (! Schema::hasColumn($tableName, 'base_amount_egp')) {
                    $table->decimal('base_amount_egp', 10, 2)->nullable()->after('amount_egp');
                }
                if (! Schema::hasColumn($tableName, 'discount_amount_egp')) {
                    $table->decimal('discount_amount_egp', 10, 2)->default(0)->after('base_amount_egp');
                }
                if (! Schema::hasColumn($tableName, 'coupon_code')) {
                    $table->string('coupon_code', 50)->nullable()->after('discount_amount_egp');
                }
                if (! Schema::hasColumn($tableName, 'payment_method')) {
                    $table->string('payment_method', 30)->nullable()->after('coupon_code');
                }
                if (! Schema::hasColumn($tableName, 'transaction_reference')) {
                    $table->string('transaction_reference', 150)->nullable()->after('payment_method');
                }
                if (! Schema::hasColumn($tableName, 'sender_reference')) {
                    $table->string('sender_reference', 200)->nullable()->after('transaction_reference');
                }
                if (! Schema::hasColumn($tableName, 'receipt_path')) {
                    $table->string('receipt_path', 500)->nullable()->after('sender_reference');
                }
                if (! Schema::hasColumn($tableName, 'receipt_disk')) {
                    $table->string('receipt_disk', 30)->default('local')->after('receipt_path');
                }
                if (! Schema::hasColumn($tableName, 'receipt_original_name')) {
                    $table->string('receipt_original_name', 255)->nullable()->after('receipt_disk');
                }
                if (! Schema::hasColumn($tableName, 'receipt_mime_type')) {
                    $table->string('receipt_mime_type', 100)->nullable()->after('receipt_original_name');
                }
                if (! Schema::hasColumn($tableName, 'is_free_promo')) {
                    $table->boolean('is_free_promo')->default(false)->after('receipt_mime_type');
                }
                if (! Schema::hasColumn($tableName, 'reviewed_by')) {
                    $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($tableName, 'reviewed_at')) {
                    $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }
                if (! Schema::hasColumn($tableName, 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('reviewed_at');
                }
            });
        }

        // 3. Add receipt & rejection reason columns to all 3 invoice tables for complete audit trail
        foreach (['student_invoices', 'teacher_invoices', 'center_invoices'] as $invoiceTable) {
            Schema::table($invoiceTable, function (Blueprint $table) use ($invoiceTable) {
                if (! Schema::hasColumn($invoiceTable, 'transaction_reference')) {
                    $table->string('transaction_reference', 150)->nullable()->after('sender_reference');
                }
                if (! Schema::hasColumn($invoiceTable, 'receipt_path')) {
                    $table->string('receipt_path', 500)->nullable()->after('transaction_reference');
                }
                if (! Schema::hasColumn($invoiceTable, 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['student_invoices', 'teacher_invoices', 'center_invoices'] as $invoiceTable) {
            Schema::table($invoiceTable, function (Blueprint $table) use ($invoiceTable) {
                $cols = array_values(array_filter(
                    ['transaction_reference', 'receipt_path', 'rejection_reason'],
                    fn (string $c) => Schema::hasColumn($invoiceTable, $c)
                ));
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        foreach (['student_subscriptions', 'teacher_subscriptions', 'center_subscriptions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'reviewed_by')) {
                    $table->dropConstrainedForeignId('reviewed_by');
                }
                $cols = array_values(array_filter([
                    'base_amount_egp',
                    'discount_amount_egp',
                    'coupon_code',
                    'payment_method',
                    'transaction_reference',
                    'sender_reference',
                    'receipt_path',
                    'receipt_disk',
                    'receipt_original_name',
                    'receipt_mime_type',
                    'is_free_promo',
                    'reviewed_at',
                    'rejection_reason',
                ], fn (string $c) => Schema::hasColumn($tableName, $c)));
                if (! empty($cols)) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::table('platform_subscription_plans', function (Blueprint $table) {
            $cols = array_values(array_filter([
                'original_price_egp',
                'original_term_price_egp',
                'original_annual_price_egp',
                'discount_percent',
                'free_promo_enabled',
                'free_promo_until',
                'free_promo_label_ar',
                'free_promo_label_en',
            ], fn (string $c) => Schema::hasColumn('platform_subscription_plans', $c)));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
