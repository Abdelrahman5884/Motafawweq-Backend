<?php

namespace Tests\Feature\Admin;

use App\Enums\DirectPaymentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PayoutRequestStatus;
use App\Enums\UserRole;
use App\Models\CenterInvoice;
use App\Models\DiscountCoupon;
use App\Models\PlatformPayoutRequest;
use App\Models\SecurityAuditLog;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\TeacherInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinancialsPricingPayoutsAndCouponsTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_overview_computes_real_gmv_commissions_and_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->create(['role' => UserRole::TEACHER]);
        $student = User::factory()->create();
        $center = User::factory()->center()->create();

        TeacherInvoice::create([
            'teacher_id' => $teacher->id,
            'description_ar' => 'اشتراك معلم برو',
            'base_amount_egp' => 150,
            'discount_amount_egp' => 0,
            'final_amount_egp' => 150,
            'payment_method' => PaymentMethod::INSTAPAY,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);

        StudentInvoice::create([
            'student_id' => $student->id,
            'description_ar' => 'اشتراك طالب برو',
            'base_amount_egp' => 80,
            'discount_amount_egp' => 0,
            'final_amount_egp' => 80,
            'payment_method' => PaymentMethod::VODAFONE,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);

        CenterInvoice::create([
            'invoice_number' => CenterInvoice::generateUniqueInvoiceNumber(),
            'center_id' => $center->id,
            'description_ar' => 'اشتراك سنتر برو',
            'base_amount_egp' => 400,
            'discount_amount_egp' => 0,
            'final_amount_egp' => 400,
            'payment_method' => PaymentMethod::INSTAPAY,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
        ]);

        StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'amount_egp' => 1000,
            'payment_method' => PaymentMethod::VODAFONE,
            'student_name_ar' => $student->name,
            'course_name_ar' => 'فيزياء',
            'status' => DirectPaymentStatus::CONFIRMED,
            'paid_at' => now(),
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($admin, ['access']);

        $res = $this->getJson('/api/v1/admin/financials/overview')->assertOk();
        $this->assertEquals(630.0, (float) $res->json('data.subscriptionRevenueEgp'));
        $this->assertEquals(1000.0, (float) $res->json('data.directPaymentsVolumeEgp'));
        $this->assertEquals(1630.0, (float) $res->json('data.gmvEgp'));
        // 630 subscriptions + 20% of 1000 direct = 830 EGP
        $this->assertEquals(830.0, (float) $res->json('data.platformCommissionEgp'));
        $this->assertEquals(830.0, (float) $res->json('data.grossPlatformRevenueEgp'));

        // Exact per-role revenue breakdown: Teacher = 150 sub + 200 comm = 350 | Student = 80 | Center = 400
        $this->assertEquals(350.0, (float) $res->json('data.roleBreakdown.teacher.revenueEgp'));
        $this->assertEquals(80.0, (float) $res->json('data.roleBreakdown.student.revenueEgp'));
        $this->assertEquals(400.0, (float) $res->json('data.roleBreakdown.center.revenueEgp'));
        $this->assertCount(3, $res->json('data.recentInvoices'));
        $this->assertEquals(830.0, (float) $res->json('data.netProfitEgp'));

        // Record manual cost with note -> immediately deducts from netProfitEgp
        $expRes = $this->postJson('/api/v1/admin/financials/expenses', [
            'title' => 'سيرفر استضافة سحابي <b>AWS</b>',
            'category' => 'hosting',
            'amountEgp' => 230,
            'note' => 'فاتورة شهر نوفمبر للسيرفر الرئيسي وقاعدة البيانات',
            'expenseDate' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.amountEgp', 230)
            ->assertJsonPath('data.note', 'فاتورة شهر نوفمبر للسيرفر الرئيسي وقاعدة البيانات');

        $this->assertStringNotContainsString('<b>', (string) $expRes->json('data.title'));
        $expUuid = (string) $expRes->json('data.id');

        $afterExp = $this->getJson('/api/v1/admin/financials/overview')->assertOk();
        $this->assertEquals(230.0, (float) $afterExp->json('data.manualExpensesEgp'));
        $this->assertEquals(600.0, (float) $afterExp->json('data.netProfitEgp'));

        // Update manual cost & note -> netProfitEgp updates live
        $this->putJson("/api/v1/admin/financials/expenses/{$expUuid}", [
            'amountEgp' => 330,
            'note' => 'تحديث الفاتورة بعد إضافة مساحة النسخ الاحتياطي',
        ])->assertOk()
            ->assertJsonPath('data.amountEgp', 330)
            ->assertJsonPath('data.note', 'تحديث الفاتورة بعد إضافة مساحة النسخ الاحتياطي');

        $this->assertEquals(500.0, (float) $this->getJson('/api/v1/admin/financials/overview')->json('data.netProfitEgp'));

        // Delete manual cost -> netProfitEgp returns to 830
        $this->deleteJson("/api/v1/admin/financials/expenses/{$expUuid}")->assertOk();
        $this->assertSoftDeleted('platform_expenses', ['uuid' => $expUuid]);
        $this->assertEquals(830.0, (float) $this->getJson('/api/v1/admin/financials/overview')->json('data.netProfitEgp'));
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.expense.created')->exists());
    }

    public function test_subscription_plans_crud_reset_defaults_and_public_catalog(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['access']);

        // First read auto-seeds 9 default plans (3 center, 3 teacher, 3 student) with full features & 3-cycle prices
        $listRes = $this->getJson('/api/v1/admin/financials/plans')->assertOk();
        $this->assertCount(9, $listRes->json('data'));
        $studentPlus = collect($listRes->json('data'))->firstWhere('slug', 'plan-student-plus');
        $this->assertNotNull($studentPlus);
        $this->assertEquals(50.0, (float) $studentPlus['priceEgp']);
        $this->assertEquals(200.0, (float) $studentPlus['termPriceEgp']);
        $this->assertEquals(350.0, (float) $studentPlus['annualPriceEgp']);
        $this->assertGreaterThanOrEqual(5, count($studentPlus['featuresAr']));

        // Create custom VIP plan
        $createRes = $this->postJson('/api/v1/admin/financials/plans', [
            'targetRole' => 'teacher',
            'nameAr' => 'باقة المعلم الذهبية VIP',
            'nameEn' => 'Teacher Gold VIP',
            'priceEgp' => 300,
            'annualPriceEgp' => 2500,
            'commissionPercent' => 3,
            'isPopular' => true,
            'featuresAr' => ['دعم مخصص 24/7', 'مساحة سحابية غير محدودة'],
        ])->assertStatus(201);

        $planUuid = (string) $createRes->json('data.id');
        $this->assertEquals(300.0, (float) $createRes->json('data.priceEgp'));

        // Update price & toggle active off
        $this->putJson("/api/v1/admin/financials/plans/{$planUuid}", [
            'priceEgp' => 350,
        ])->assertOk()->assertJsonPath('data.priceEgp', 350);

        $this->patchJson("/api/v1/admin/financials/plans/{$planUuid}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        // Public pricing catalog excludes inactive plan
        $publicRes = $this->getJson('/api/v1/public/pricing-plans?role=teacher')->assertOk();
        $this->assertFalse(collect($publicRes->json('data'))->contains('id', $planUuid));
        $this->assertCount(3, $publicRes->json('data'));

        // Delete & Reset defaults
        $this->deleteJson("/api/v1/admin/financials/plans/{$planUuid}")->assertOk();
        $resetRes = $this->postJson('/api/v1/admin/financials/plans/reset-defaults')->assertOk();
        $this->assertCount(9, $resetRes->json('data'));
    }

    public function test_payout_encryption_atomic_approval_prevents_double_payout_and_supports_idempotency(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->create(['role' => UserRole::TEACHER]);
        Sanctum::actingAs($admin, ['access']);

        // 1. Create payout request with sensitive destination
        $createRes = $this->postJson('/api/v1/admin/financials/payouts', [
            'recipientUserId' => $teacher->id,
            'recipientType' => 'teacher',
            'recipientNameAr' => 'أ. محمود راضي',
            'amountEgp' => 18500,
            'payoutMethod' => 'vodafone',
            'destination' => '01011223344',
            'notesAr' => 'أرباح أكتوبر',
        ])->assertStatus(201);

        $payoutUuid = (string) $createRes->json('data.id');
        $this->assertEquals('*******3344', $createRes->json('data.destinationMasked'));
        $this->assertArrayNotHasKey('payout_destination_encrypted', $createRes->json('data'));

        // Confirm raw DB column is encrypted, NOT plaintext
        $rawDest = DB::table('platform_payout_requests')->where('uuid', $payoutUuid)->value('payout_destination_encrypted');
        $this->assertNotEquals('01011223344', $rawDest);

        // 2. Approve with Idempotency-Key
        $approveRes = $this->withHeader('Idempotency-Key', 'payout-approve-001')
            ->postJson("/api/v1/admin/financials/payouts/{$payoutUuid}/approve", [
                'transferReference' => 'VF-2026-998811',
            ])->assertOk()
            ->assertJsonPath('data.status', PayoutRequestStatus::COMPLETED->value)
            ->assertJsonPath('data.transferReference', 'VF-2026-998811');

        // Replaying same Idempotency-Key returns cached 200 with replay header (no duplicate mutation)
        $this->withHeader('Idempotency-Key', 'payout-approve-001')
            ->postJson("/api/v1/admin/financials/payouts/{$payoutUuid}/approve")
            ->assertOk()
            ->assertHeader('X-Idempotent-Replayed', 'true');

        // 3. Attempting a fresh second approval (with a NEW Idempotency-Key / without the old header) MUST fail with 409 Conflict!
        $this->flushHeaders()
            ->withHeader('Idempotency-Key', 'payout-approve-002')
            ->postJson("/api/v1/admin/financials/payouts/{$payoutUuid}/approve")
            ->assertStatus(409)
            ->assertJson(['error_code' => 'PAYOUT_ALREADY_PROCESSED']);

        // 4. Rejecting an already-completed payout also fails with 409 Conflict
        $this->flushHeaders()
            ->postJson("/api/v1/admin/financials/payouts/{$payoutUuid}/reject", [
                'reason' => 'Too late',
            ])->assertStatus(409);

        // 5. Reject a fresh pending payout
        $pending = PlatformPayoutRequest::create([
            'recipient_user_id' => $teacher->id,
            'recipient_type' => 'teacher',
            'recipient_name_ar' => 'معلم آخر',
            'amount_egp' => 5000,
            'payout_method' => 'instapay',
        ]);

        $this->postJson("/api/v1/admin/financials/payouts/{$pending->uuid}/reject", [
            'reason' => 'بيانات الحساب غير مطابقة',
        ])->assertOk()
            ->assertJsonPath('data.status', PayoutRequestStatus::REJECTED->value)
            ->assertJsonPath('data.rejectionReason', 'بيانات الحساب غير مطابقة');

        $this->assertTrue(SecurityAuditLog::where('event', 'admin.payout.approved')->exists());
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.payout.rejected')->exists());
    }

    public function test_admin_discount_coupons_crud_and_cross_portal_integration(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create();
        Sanctum::actingAs($admin, ['access']);

        $createRes = $this->postJson('/api/v1/admin/financials/coupons', [
            'code' => 'motafawweq30',
            'discountPercent' => 30,
            'maxUses' => 100,
            'expiryDate' => now()->addMonths(2)->toDateString(),
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'MOTAFAWWEQ30')
            ->assertJsonPath('data.discountPercent', 30);

        $couponUuid = (string) $createRes->json('data.id');

        // Duplicate code rejected
        $this->postJson('/api/v1/admin/financials/coupons', [
            'code' => 'MOTAFAWWEQ30',
            'discountPercent' => 15,
        ])->assertStatus(422);

        // Student can immediately validate the admin-created coupon!
        Sanctum::actingAs($student, ['access']);
        $this->postJson('/api/v1/student/billing/coupons/validate', [
            'code' => 'MOTAFAWWEQ30',
            'amount_egp' => 100,
        ])->assertOk()
            ->assertJsonPath('data.discountPercent', 30);

        // Admin disables and deletes coupon
        Sanctum::actingAs($admin, ['access']);
        $this->patchJson("/api/v1/admin/financials/coupons/{$couponUuid}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.isActive', false);

        $this->deleteJson("/api/v1/admin/financials/coupons/{$couponUuid}")->assertOk();
        $this->assertDatabaseMissing('discount_coupons', ['uuid' => $couponUuid]);
        $this->assertEquals(0, DiscountCoupon::where('code', 'MOTAFAWWEQ30')->count());
    }
}
