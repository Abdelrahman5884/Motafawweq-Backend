<?php

namespace Tests\Feature\Center;

use App\Enums\CenterPlanTier;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\DiscountCoupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterSubscriptionAndQuotasTest extends TestCase
{
    use RefreshDatabase;

    private function createFreeCenter(): User
    {
        return User::factory()->center()->create([
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_new_center_defaults_to_free_tier_and_auth_me_exposes_center_entitlements(): void
    {
        $center = $this->createFreeCenter();
        Sanctum::actingAs($center, ['access']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'center')
            ->assertJsonPath('data.plan_tier', 'free')
            ->assertJsonPath('data.unlocked_features.qr_scanner', false)
            ->assertJsonPath('data.unlocked_features.master_schedule', false)
            ->assertJsonPath('data.unlocked_features.staff_management', false)
            ->assertJsonPath('data.unlocked_features.financials', false)
            ->assertJsonPath('data.unlocked_features.audit_logs', false)
            ->assertJsonPath('data.unlocked_features.executive_reports', false)
            ->assertJsonPath('data.unlocked_features.cloud_backup', false)
            ->assertJsonPath('data.plan_quotas.maxUsers', 20)
            ->assertJsonPath('data.plan_quotas.maxGroups', 2)
            ->assertJsonPath('data.plan_quotas.maxBranches', 1)
            ->assertJsonPath('data.plan_quotas.maxRooms', 1)
            ->assertJsonPath('data.plan_quotas.maxTeachers', 2)
            ->assertJsonPath('data.plan_quotas.maxStaff', 0);
    }

    public function test_free_tier_enforces_quotas_and_blocks_paid_features(): void
    {
        $center = $this->createFreeCenter();
        Sanctum::actingAs($center, ['access']);

        // 1 branch allowed on Free; 2nd blocked with PLAN_QUOTA_EXCEEDED
        $branchRes = $this->postJson('/api/v1/center/branches', ['name_ar' => 'الفرع الرئيسي'])
            ->assertStatus(201);
        $branchId = $branchRes->json('data.id');

        $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع ثاني'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('feature', 'branches');

        // 1 room allowed on Free; 2nd blocked
        $roomRes = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 1', 'branch_id' => $branchId, 'capacity' => 40])->assertStatus(201);
        $roomId = $roomRes->json('data.id');
        $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 2', 'branch_id' => $branchId, 'capacity' => 40])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('feature', 'rooms');

        // 2 teachers allowed on Free; 3rd blocked
        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/api/v1/center/teachers', [
                'name_ar' => "مدرس {$i}",
                'subject_ar' => 'رياضيات',
                'email' => "t{$i}@center.test",
                'branch_id' => $branchId,
            ])->assertStatus(201);
        }
        $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'مدرس 3',
            'subject_ar' => 'فيزياء',
            'email' => 't3@center.test',
            'branch_id' => $branchId,
        ])->assertStatus(403)->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED');

        // 2 groups allowed on Free; 3rd blocked
        $g1 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة 1',
            'subject_ar' => 'رياضيات',
            'grade_ar' => '3 ثانوي',
            'branch_id' => $branchId,
            'room_id' => $roomId,
            'slots' => [['day' => 'saturday', 'start_time' => '10:00', 'end_time' => '12:00']],
        ])->assertStatus(201)->json('data.id');

        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة 2',
            'subject_ar' => 'فيزياء',
            'grade_ar' => '3 ثانوي',
            'branch_id' => $branchId,
            'room_id' => $roomId,
            'slots' => [['day' => 'sunday', 'start_time' => '10:00', 'end_time' => '12:00']],
        ])->assertStatus(201);

        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة 3',
            'subject_ar' => 'كيمياء',
            'grade_ar' => '3 ثانوي',
            'branch_id' => $branchId,
            'room_id' => $roomId,
            'slots' => [['day' => 'monday', 'start_time' => '10:00', 'end_time' => '12:00']],
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('feature', 'groups');

        // 20 active users max on Free (2 teachers already exist => 18 students allowed, 19th blocked)
        for ($s = 1; $s <= 18; $s++) {
            $this->postJson("/api/v1/center/groups/{$g1}/students", [
                'name_ar' => "طالب {$s}",
                'phone' => '0109900'.str_pad((string) $s, 4, '0', STR_PAD_LEFT),
            ])->assertStatus(201);
        }
        $this->postJson("/api/v1/center/groups/{$g1}/students", [
            'name_ar' => 'طالب 19 يتجاوز الحد',
            'phone' => '01099009999',
        ])->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('feature', 'users');

        // Paid feature gates on Free tier
        $this->getJson('/api/v1/center/schedule?day=saturday')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'plus');

        $this->getJson('/api/v1/center/staff')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'plus');

        $this->getJson('/api/v1/center/financials/overview')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'plus');

        $this->postJson('/api/v1/center/attendance/smart-scan', ['code' => 'STD-123'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'plus');

        $this->getJson('/api/v1/center/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'pro');

        $this->getJson('/api/v1/center/reports/executive')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'pro');

        $this->postJson('/api/v1/center/backups')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('required_plan', 'pro');

        $this->putJson('/api/v1/center/settings', ['custom_domain' => 'mycenter.edu.eg'])
            ->assertStatus(422);
    }

    public function test_center_billing_lifecycle_coupons_authoritative_pricing_and_cancel_before_switch(): void
    {
        $center = $this->createFreeCenter();
        Sanctum::actingAs($center, ['access']);

        $dbCoupon = DiscountCoupon::create([
            'code' => 'VIPCENTER30',
            'discount_percent' => 30,
            'is_active' => true,
            'max_uses' => 2,
            'used_count' => 0,
        ]);

        // Validate DB coupon & fallback promo code
        $this->postJson('/api/v1/center/billing/validate-coupon', [
            'code' => 'CENTER2026',
            'order_amount' => 800,
        ])->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.discountPercent', 20)
            ->assertJsonPath('data.finalAmountEgp', 640);

        // Subscribe to Plus Term (authoritative base = 800 EGP, 30% coupon => 560 EGP)
        $subRes = $this->postJson('/api/v1/center/billing/subscribe', [
            'plan_tier' => 'plan-center-plus',
            'billing_period' => 'term',
            'payment_method' => 'instapay',
            'coupon_code' => 'VIPCENTER30',
            'amount_egp' => 1, // Tampered client price MUST be ignored!
        ])->assertOk();

        $subRes->assertJsonPath('data.activeSub.planTier', 'plus')
            ->assertJsonPath('data.invoice.baseAmountEgp', 800)
            ->assertJsonPath('data.invoice.discountAmountEgp', 240)
            ->assertJsonPath('data.invoice.finalAmountEgp', 560);

        $this->assertEquals(1, $dbCoupon->fresh()->used_count);

        // Financials now unlocked on Plus, but Pro Cloud Backup still blocked
        $this->getJson('/api/v1/center/financials/overview')->assertOk();
        $this->postJson('/api/v1/center/backups')->assertStatus(403);

        // Attempting to subscribe to Pro without cancelling Plus returns 409 Conflict
        $this->postJson('/api/v1/center/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'monthly',
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVE_SUBSCRIPTION_EXISTS');

        // Cancel Plus -> reverts to Free
        $this->postJson('/api/v1/center/billing/cancel')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.previousPlan', 'plus');

        // Subscribe to Pro Annual (3000 EGP) -> unlocks Pro Cloud Backup & White-Label
        $this->postJson('/api/v1/center/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'annual',
            'payment_method' => 'vodafone',
        ])->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.invoice.finalAmountEgp', 3000);

        $this->postJson('/api/v1/center/backups')->assertStatus(201);
        $this->putJson('/api/v1/center/settings', ['custom_domain' => 'rowad.edu.eg'])->assertOk();
    }

    public function test_expired_paid_subscription_auto_downgrades_to_free(): void
    {
        $center = $this->createFreeCenter();
        $profile = CenterProfile::create(['user_id' => $center->id, 'name_ar' => 'سنتر منتهي']);
        $profile->forceFill(['plan_tier' => CenterPlanTier::PRO])->save();

        CenterSubscription::create([
            'center_id' => $center->id,
            'plan_tier' => CenterPlanTier::PRO,
            'plan_name_ar' => 'باقة السنتر برو',
            'billing_period' => 'monthly',
            'amount_egp' => 400,
            'starts_at' => now()->subDays(40),
            'renews_at' => now()->subDays(5),
            'status' => 'active',
        ]);

        Sanctum::actingAs($center, ['access']);

        $this->getJson('/api/v1/center/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free');

        $this->assertEquals(CenterPlanTier::FREE, $profile->fresh()->plan_tier);
    }
}
