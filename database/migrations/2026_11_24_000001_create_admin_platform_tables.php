<?php

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Enums\PayoutRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_champions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name_ar', 160);
            $table->string('name_en', 160)->nullable();
            $table->string('title_ar', 220)->nullable();
            $table->string('title_en', 220)->nullable();
            $table->string('month_ar', 120)->nullable();
            $table->string('month_en', 120)->nullable();
            $table->string('school_ar', 220)->nullable();
            $table->string('school_en', 220)->nullable();
            $table->string('score_ar', 80)->nullable();
            $table->string('score_en', 80)->nullable();
            $table->string('rank_ar', 120)->nullable();
            $table->string('rank_en', 120)->nullable();
            $table->string('streak_ar', 80)->nullable();
            $table->string('streak_en', 80)->nullable();
            $table->string('badge_ar', 140)->nullable();
            $table->string('badge_en', 140)->nullable();
            $table->string('image_url', 500)->default('/characters/real-male-champ-1.png');

            $table->boolean('is_pinned')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->date('added_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_pinned', 'display_order', 'deleted_at'], 'champions_pinned_order_idx');
        });

        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('title_ar', 220);
            $table->string('title_en', 220)->nullable();
            $table->text('content_ar');
            $table->text('content_en')->nullable();

            $table->string('audience', 30)->default(AnnouncementAudience::ALL->value)->index();
            $table->string('priority', 20)->default(AnnouncementPriority::NORMAL->value)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'audience', 'priority', 'deleted_at'], 'announcements_active_audience_idx');
        });

        Schema::create('platform_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 100)->unique();

            $table->string('target_role', 20)->index(); // center | teacher | student
            $table->string('tier_key', 30)->default('custom'); // free | plus | pro | custom
            $table->string('name_ar', 180);
            $table->string('name_en', 180)->nullable();
            $table->string('billing_cycle', 20)->default('monthly'); // monthly | term | annual

            $table->decimal('price_egp', 10, 2)->default(0.00);
            $table->decimal('term_price_egp', 10, 2)->nullable();
            $table->decimal('annual_price_egp', 10, 2)->default(0.00);
            $table->decimal('commission_percent', 5, 2)->nullable();

            $table->string('badge_ar', 120)->nullable();
            $table->string('badge_en', 120)->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true)->index();

            $table->json('features_ar')->nullable();
            $table->json('features_en')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['target_role', 'is_active', 'display_order'], 'plans_role_active_order_idx');
        });

        Schema::create('platform_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_number', 50)->unique();

            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_type', 20)->default('teacher')->index(); // teacher | center
            $table->string('recipient_name_ar', 180);
            $table->string('recipient_name_en', 180)->nullable();

            $table->decimal('amount_egp', 12, 2);
            $table->string('payout_method', 40)->default('vodafone'); // vodafone | instapay | bank | fawry
            $table->string('method_label_ar', 180)->nullable();
            $table->string('method_label_en', 180)->nullable();
            $table->text('payout_destination_encrypted')->nullable();
            $table->string('payout_destination_masked', 120)->nullable();

            $table->string('status', 20)->default(PayoutRequestStatus::PENDING->value)->index();
            $table->text('notes_ar')->nullable();
            $table->text('notes_en')->nullable();
            $table->string('transfer_reference', 120)->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'requested_at'], 'payout_requests_status_date_idx');
            $table->index(['recipient_user_id', 'status'], 'payout_requests_recipient_status_idx');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 20)->default('string'); // boolean | integer | float | string | json
            $table->string('group', 40)->default('policies')->index();
            $table->string('description_ar', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_payout_requests');
        Schema::dropIfExists('platform_subscription_plans');
        Schema::dropIfExists('platform_announcements');
        Schema::dropIfExists('platform_champions');
    }
};
