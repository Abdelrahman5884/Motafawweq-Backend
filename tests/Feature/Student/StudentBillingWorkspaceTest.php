<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\DirectPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\DiscountCoupon;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentBillingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createStudent(string $email = 'student.bill@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'أحمد علاء',
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_overview_returns_three_official_tiers_and_default_free_subscription(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/billing');
        $res->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.activeSub.isFree', true)
            ->assertJsonPath('data.activeSub.amountEgp', 0);

        $plans = collect($res->json('data.plans'));
        $this->assertCount(3, $plans);
        $this->assertSame(['free', 'plus', 'pro'], $plans->pluck('tier')->all());
        $this->assertSame(0, $plans[0]['pricing']['monthly']['priceEgp']);
        $this->assertSame(99, $plans[1]['pricing']['monthly']['priceEgp']);
        $this->assertSame(395, $plans[1]['pricing']['term']['priceEgp']);
        $this->assertSame(690, $plans[1]['pricing']['annual']['priceEgp']);
        $this->assertSame(179, $plans[2]['pricing']['monthly']['priceEgp']);
        $this->assertSame(715, $plans[2]['pricing']['term']['priceEgp']);
        $this->assertSame(1250, $plans[2]['pricing']['annual']['priceEgp']);
    }

    public function test_student_can_subscribe_with_coupon_and_upgrade_persistently(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 12, 0, 0, 'UTC'));

        $student = $this->createStudent();
        $coupon = DiscountCoupon::create([
            'code' => 'STD20',
            'discount_percent' => 20,
            'max_discount_egp' => 100,
            'min_order_egp' => 50,
            'is_active' => true,
            'max_uses' => 5,
            'used_count' => 0,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Validate coupon on Plus Term (395 EGP -> 20% = 79 EGP off -> 316 EGP)
        $valRes = $this->postJson('/api/v1/student/billing/coupons/validate', [
            'code' => 'std20',
            'amount_egp' => 395,
        ]);
        $valRes->assertOk()
            ->assertJsonPath('data.discountPercent', 20)
            ->assertJsonPath('data.discountEgp', 79)
            ->assertJsonPath('data.finalAmountEgp', 316);

        // 2. Subscribe to Plus Term using coupon
        $subRes = $this->postJson('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'plus',
            'billing_period' => 'term',
            'payment_method' => 'vodafone',
            'coupon_code' => 'STD20',
            'sender_phone' => '01012345678',
        ]);
        $subRes->assertCreated()
            ->assertJsonPath('data.activeSub.planTier', 'plus')
            ->assertJsonPath('data.activeSub.billingPeriod', 'term')
            ->assertJsonPath('data.activeSub.isFree', false)
            ->assertJsonPath('data.activeSub.amountEgp', 316)
            ->assertJsonPath('data.invoice.baseAmountEgp', 395)
            ->assertJsonPath('data.invoice.discountAmountEgp', 79)
            ->assertJsonPath('data.invoice.amountEgp', 316)
            ->assertJsonPath('data.invoice.couponCode', 'STD20');

        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(1, StudentSubscription::where('student_id', $student->id)->where('status', 'active')->count());
        $this->assertSame(1, StudentInvoice::where('student_id', $student->id)->count());

        // 3. Upgrade to Pro Annual (1250 EGP, no coupon) -> old Plus becomes superseded!
        $upRes = $this->postJson('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'annual',
            'payment_method' => 'instapay',
            'sender_ipa' => 'ahmed@instapay',
        ]);
        $upRes->assertCreated()
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.billingPeriod', 'annual')
            ->assertJsonPath('data.activeSub.amountEgp', 1250);

        $this->assertSame(1, StudentSubscription::where('student_id', $student->id)->where('status', 'active')->count());
        $this->assertSame(1, StudentSubscription::where('student_id', $student->id)->where('status', 'superseded')->count());

        // 4. Add a pending direct teacher payment and verify overview merges both invoice types cleanly
        $teacher = User::factory()->create([
            'name' => 'أ. محمد سامي',
            'email' => 't.bill@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'amount_egp' => 250,
            'payment_method' => PaymentMethod::FAWRY,
            'student_name_ar' => $student->name,
            'course_name_ar' => 'كورس الفيزياء الشامل',
            'status' => DirectPaymentStatus::PENDING,
            'paid_at' => now()->addMinute(),
        ]);

        $ov = $this->getJson('/api/v1/student/billing');
        $ov->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'pro');

        $invoices = collect($ov->json('data.invoices'));
        $this->assertCount(3, $invoices);
        $this->assertSame('pending', $invoices->firstWhere('kind', 'course_payment')['status']);
        $this->assertSame('paid', $invoices->firstWhere('kind', 'subscription')['status']);
    }

    public function test_invalid_coupon_rejected_and_expired_subscription_reverts_to_free(): void
    {
        $student = $this->createStudent('std.exp@motafawweq.com');
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Invalid coupon rejected cleanly with 422
        $this->postJson('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'monthly',
            'payment_method' => 'vodafone',
            'coupon_code' => 'NO_SUCH_COUPON',
        ])->assertUnprocessable();

        $this->assertSame(0, StudentSubscription::where('student_id', $student->id)->count());

        // Past-due paid subscription auto-expires back to Free on overview
        $expired = StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PRO,
            'plan_name_ar' => 'باقة الطالب برو (Pro) — شهرياً',
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => 179,
            'starts_at' => now()->subDays(40),
            'renews_at' => now()->subDays(10),
            'status' => 'active',
        ]);

        $res = $this->getJson('/api/v1/student/billing');
        $res->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.activeSub.isFree', true);

        $this->assertSame('expired', $expired->fresh()->status);
    }

    public function test_billing_subscribe_blocks_guests_and_teachers(): void
    {
        $this->postJson('/api/v1/student/billing/subscribe', ['plan_tier' => 'plus'])->assertUnauthorized();

        $teacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->postJson('/api/v1/student/billing/subscribe', ['plan_tier' => 'plus'])->assertForbidden();
    }
}
