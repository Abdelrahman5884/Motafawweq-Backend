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
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function createSuperAdmin(): User
    {
        return User::factory()->admin()->create([
            'email' => 'super.billing@motafawweq.com',
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function fakeReceiptPng(string $name = 'receipt.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $pngBytes);
    }

    private function fakeReceiptPdf(string $name = 'receipt.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    public function test_overview_returns_three_official_tiers_and_default_free_subscription(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/billing');
        $res->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.activeSub.isFree', true)
            ->assertJsonPath('data.activeSub.canCancel', false)
            ->assertJsonPath('data.activeSub.mustCancelToSwitch', false)
            ->assertJsonPath('data.activeSub.amountEgp', 0)
            ->assertJsonPath('data.pendingRequest', null);

        $plans = collect($res->json('data.plans'));
        $this->assertCount(3, $plans);
        $this->assertSame(['free', 'plus', 'pro'], $plans->pluck('tier')->all());
        $this->assertSame(0, $plans[0]['pricing']['monthly']['priceEgp']);
        $this->assertSame(50, $plans[1]['pricing']['monthly']['priceEgp']);
        $this->assertSame(200, $plans[1]['pricing']['term']['priceEgp']);
        $this->assertSame(350, $plans[1]['pricing']['annual']['priceEgp']);
        $this->assertSame(80, $plans[2]['pricing']['monthly']['priceEgp']);
        $this->assertSame(320, $plans[2]['pricing']['term']['priceEgp']);
        $this->assertSame(560, $plans[2]['pricing']['annual']['priceEgp']);
    }

    public function test_paid_subscription_requires_receipt_super_admin_approval_and_cancel_before_switch(): void
    {
        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 12, 0, 0, 'UTC'));

        $student = $this->createStudent();
        $admin = $this->createSuperAdmin();
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

        // 1. Validate coupon on Plus Term (200 EGP -> 20% = 40 EGP off -> 160 EGP)
        $this->postJson('/api/v1/student/billing/coupons/validate', [
            'code' => 'std20',
            'amount_egp' => 200,
        ])->assertOk()
            ->assertJsonPath('data.discountPercent', 20)
            ->assertJsonPath('data.discountEgp', 40)
            ->assertJsonPath('data.finalAmountEgp', 160);

        // 2. Paid subscription WITHOUT receipt & transaction code is rejected (422)
        $this->postJson('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'plus',
            'billing_period' => 'term',
            'payment_method' => 'vodafone',
            'sender_phone' => '01012345678',
        ])->assertUnprocessable();

        // 3. Paid subscription WITH receipt & transaction_ref enters pending_review (NOT active yet!)
        $subRes = $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'plus',
            'billing_period' => 'term',
            'payment_method' => 'vodafone',
            'coupon_code' => 'STD20',
            'sender_phone' => '01012345678',
            'transaction_ref' => 'VF-STD-99881',
            'receipt' => $this->fakeReceiptPng('student-receipt.png'),
        ], ['Accept' => 'application/json']);

        $subRes->assertCreated()
            ->assertJsonPath('data.requiresApproval', true)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.pendingRequest.planTier', 'plus')
            ->assertJsonPath('data.pendingRequest.transactionReference', 'VF-STD-99881')
            ->assertJsonPath('data.pendingRequest.hasReceipt', true)
            ->assertJsonPath('data.invoice.baseAmountEgp', 200)
            ->assertJsonPath('data.invoice.discountAmountEgp', 40)
            ->assertJsonPath('data.invoice.amountEgp', 160)
            ->assertJsonPath('data.invoice.status', 'pending')
            ->assertJsonPath('data.invoice.couponCode', 'STD20');

        $pendingUuid = (string) $subRes->json('data.pendingRequest.id');
        $this->assertSame(1, $coupon->fresh()->used_count);

        // 4. Duplicate subscribe while pending_review exists is blocked (409 PENDING_SUBSCRIPTION_EXISTS)
        $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'monthly',
            'payment_method' => 'instapay',
            'transaction_ref' => 'IP-DUP-111',
            'receipt' => $this->fakeReceiptPng('dup.png'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PENDING_SUBSCRIPTION_EXISTS');

        // 5. Super Admin approves request -> activates Plus & marks invoice paid
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/admin/financials/subscription-requests/student/{$pendingUuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'plus')
            ->assertJsonPath('data.activeSub.billingPeriod', 'term')
            ->assertJsonPath('data.activeSub.isFree', false)
            ->assertJsonPath('data.activeSub.canCancel', true)
            ->assertJsonPath('data.activeSub.mustCancelToSwitch', true)
            ->assertJsonPath('data.pendingRequest', null);

        // 6. Direct switch to Pro without cancelling Plus returns 409 ACTIVE_SUBSCRIPTION_EXISTS
        $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'annual',
            'payment_method' => 'instapay',
            'sender_ipa' => 'ahmed@instapay',
            'transaction_ref' => 'IP-PRO-7788',
            'receipt' => $this->fakeReceiptPng('pro.png'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVE_SUBSCRIPTION_EXISTS');

        // 7. Cancel active Plus subscription -> reverts immediately to Free
        $this->postJson('/api/v1/student/billing/cancel')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.previousPlan', 'plus')
            ->assertJsonPath('data.cancelledType', 'active_subscription');

        $this->assertSame(1, StudentSubscription::where('student_id', $student->id)->where('status', 'cancelled')->count());

        // 8. Now subscribe to Pro Annual + Super Admin approves -> Pro Annual active
        $upRes = $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'annual',
            'payment_method' => 'instapay',
            'sender_ipa' => 'ahmed@instapay',
            'transaction_ref' => 'IP-PRO-9900',
            'receipt' => $this->fakeReceiptPng('pro-annual.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $proPendingUuid = (string) $upRes->json('data.pendingRequest.id');
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/admin/financials/subscription-requests/student/{$proPendingUuid}/approve")->assertOk();

        // 9. Add a pending direct teacher payment and verify overview merges both invoice types cleanly
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

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $ov = $this->getJson('/api/v1/student/billing');
        $ov->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.billingPeriod', 'annual')
            ->assertJsonPath('data.activeSub.amountEgp', 560);

        $invoices = collect($ov->json('data.invoices'));
        $this->assertCount(3, $invoices);
        $this->assertSame('pending', $invoices->firstWhere('kind', 'course_payment')['status']);
        $this->assertSame('paid', $invoices->firstWhere('kind', 'subscription')['status']);
        $this->assertSame(2, StudentInvoice::where('student_id', $student->id)->count());
    }

    public function test_student_can_cancel_pending_request_and_temporary_free_promo_activates_instantly(): void
    {
        Storage::fake('local');
        $student = $this->createStudent('std.promo@motafawweq.com');
        $admin = $this->createSuperAdmin();

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Submit paid Plus -> pending_review -> cancel pending request cleanly
        $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'plus',
            'billing_period' => 'monthly',
            'payment_method' => 'vodafone',
            'sender_phone' => '01012345678',
            'transaction_ref' => 'VF-CANCEL-ME',
            'receipt' => $this->fakeReceiptPng('cancel-me.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson('/api/v1/student/billing/cancel')
            ->assertOk()
            ->assertJsonPath('data.cancelledType', 'pending_request')
            ->assertJsonPath('data.pendingRequest', null);

        // 2. Super Admin makes Student Pro temporarily FREE for 7 days
        PlatformSubscriptionPlan::create([
            'slug' => 'plan-student-pro',
            'target_role' => 'student',
            'tier_key' => 'pro',
            'name_ar' => 'باقة الطالب برو (Pro)',
            'name_en' => 'Student Pro',
            'billing_cycle' => 'monthly',
            'price_egp' => 80,
            'term_price_egp' => 320,
            'annual_price_egp' => 560,
            'is_popular' => true,
            'is_active' => true,
            'free_promo_enabled' => true,
            'free_promo_until' => now()->addDays(7),
            'free_promo_label_ar' => 'مجانية لأسبوع الامتحانات',
            'free_promo_label_en' => 'Free for Exam Week',
        ]);

        // Overview catalog reflects 0 EGP + strikethrough 80 EGP + 100% discount
        $ov = $this->getJson('/api/v1/student/billing')->assertOk();
        $proCard = collect($ov->json('data.plans'))->firstWhere('tier', 'pro');
        $this->assertTrue((bool) $proCard['isFreePromo']);
        $this->assertEquals(0.0, (float) $proCard['pricing']['monthly']['priceEgp']);
        $this->assertEquals(80.0, (float) $proCard['pricing']['monthly']['originalPriceEgp']);
        $this->assertEquals(100.0, (float) $proCard['pricing']['monthly']['discountPercent']);

        // 3. Subscribe to Pro WITHOUT receipt or transaction_ref -> activates IMMEDIATELY without Super Admin approval!
        $this->postJson('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'pro',
            'billing_period' => 'monthly',
            'payment_method' => 'vodafone',
        ])->assertCreated()
            ->assertJsonPath('data.requiresApproval', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.isFree', false)
            ->assertJsonPath('data.activeSub.isFreePromo', true)
            ->assertJsonPath('data.activeSub.canCancel', true)
            ->assertJsonPath('data.activeSub.amountEgp', 0);

        // 4. Reject flow test on a second student
        $student2 = $this->createStudent('std.reject@motafawweq.com');
        Sanctum::actingAs($student2, [TokenType::ACCESS->value]);

        $pendingRes = $this->post('/api/v1/student/billing/subscribe', [
            'plan_tier' => 'plus',
            'billing_period' => 'monthly',
            'payment_method' => 'fawry',
            'transaction_ref' => 'FWR-BAD-001',
            'receipt' => $this->fakeReceiptPdf('receipt.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $rejUuid = (string) $pendingRes->json('data.pendingRequest.id');

        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/admin/financials/subscription-requests/student/{$rejUuid}/reject", [
            'reason' => 'صورة الوصل غير واضحة',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'صورة الوصل غير واضحة');

        Sanctum::actingAs($student2, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.pendingRequest', null)
            ->assertJsonPath('data.latestRejectedRequest.rejectionReason', 'صورة الوصل غير واضحة');
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
        $this->postJson('/api/v1/student/billing/cancel')->assertUnauthorized();

        $teacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->postJson('/api/v1/student/billing/subscribe', ['plan_tier' => 'plus'])->assertForbidden();
        $this->postJson('/api/v1/student/billing/cancel')->assertForbidden();
    }
}
