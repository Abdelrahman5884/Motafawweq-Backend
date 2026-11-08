<?php

namespace Tests\Feature\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\CertificateStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\DiscountCoupon;
use App\Models\StudentDirectPayment;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherCertificatesAndFinancialsTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.cert@motafawweq.com'): User
    {
        $teacher = User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id'      => $teacher->id,
            'name_ar'      => $teacher->name,
            'name_en'      => 'Dr. Teacher Example',
            'title_ar'     => 'كبير معلمي الأحياء ومؤلف سلسلة التفوق',
            'subject_ar'   => 'الأحياء',
        ]);

        return $teacher;
    }

    protected function createStudent(string $name, string $email): User
    {
        return User::factory()->create([
            'name'              => $name,
            'email'             => $email,
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    /* ----------------------------------------------------------------- */
    /* Certificates Tests                                                */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_issue_accredited_certificate_with_qr_and_digital_signature(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('عمر طارق القاضي', 'std.cert1@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'student_id'      => $student->id,
            'student_name_ar' => 'عمر طارق القاضي',
            'student_name_en' => 'Omar Tarek',
            'student_email'   => 'std.cert1@motafawweq.com',
            'course_name_ar'  => 'ماستر كلاس الأحياء: البناء الضوئي وحركية الطاقة',
            'grade_ar'        => 'الصف الثالث الثانوي',
            'grade_percent'   => 98.5,
            'honors_title_ar' => 'شهادة تميز وتفوق بالدرجة النهائية (Full Mark)',
        ];

        $response = $this->postJson('/api/v1/teacher/certificates', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.studentNameAr', 'عمر طارق القاضي')
            ->assertJsonPath('data.gradePercent', 98.5)
            ->assertJsonPath('data.status', 'verified');

        $certNumber = $response->json('data.certNumber');
        $this->assertNotEmpty($certNumber);
        $this->assertStringStartsWith('MTF-BIO-', $certNumber);

        $this->assertDatabaseHas('course_certificates', [
            'teacher_id'         => $teacher->id,
            'certificate_number' => $certNumber,
            'status'             => CertificateStatus::VERIFIED->value,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.certificate_issued',
        ]);
    }

    public function test_public_can_verify_certificate_via_qr_serial_number_and_detect_tampering(): void
    {
        $teacher = $this->createTeacher();
        $cert = CourseCertificate::create([
            'teacher_id'      => $teacher->id,
            'student_name_ar' => 'سارة خالد منصور',
            'course_name_ar'  => 'الأحياء للثانوية العامة',
            'grade_percent'   => 99.0,
            'honors_title_ar' => 'شهادة تفوق وتميز',
            'issue_date'      => now()->toDateString(),
            'status'          => CertificateStatus::VERIFIED,
        ]);

        // 1. Valid verification
        $response = $this->getJson("/api/v1/verify-certificate/{$cert->certificate_number}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.studentNameAr', 'سارة خالد منصور')
            ->assertJsonPath('data.instructorAr', $teacher->name);

        // 2. Tampered verification check
        $cert->student_name_ar = 'اسم طالب مزور';
        $cert->saveQuietly();

        $tamperedResponse = $this->getJson("/api/v1/verify-certificate/{$cert->certificate_number}");
        $tamperedResponse->assertOk()
            ->assertJsonPath('data.isValid', false)
            ->assertJsonPath('data.status', 'tampered')
            ->assertJsonPath('data.tampered', true);

        // 3. Unknown certificate check
        $notFoundResponse = $this->getJson('/api/v1/verify-certificate/MTF-BIO-UNKNOWN-9999');
        $notFoundResponse->assertNotFound();
    }

    public function test_teacher_can_revoke_issued_certificate(): void
    {
        $teacher = $this->createTeacher();
        $cert = CourseCertificate::create([
            'teacher_id'      => $teacher->id,
            'student_name_ar' => 'مريم عادل',
            'course_name_ar'  => 'أحياء الصف الثالث الثانوي',
            'grade_percent'   => 92.0,
            'honors_title_ar' => 'شهادة إتمام',
            'issue_date'      => now()->toDateString(),
            'status'          => CertificateStatus::VERIFIED,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->patchJson("/api/v1/teacher/certificates/{$cert->uuid}/revoke", [
            'reason' => 'تم اكتشاف غش في الاختبار النهائي للمقرر',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revocationReason', 'تم اكتشاف غش في الاختبار النهائي للمقرر');

        $this->assertDatabaseHas('course_certificates', [
            'id'     => $cert->id,
            'status' => CertificateStatus::REVOKED->value,
        ]);

        // Public verify reflects revocation
        $verifyResponse = $this->getJson("/api/v1/verify-certificate/{$cert->certificate_number}");
        $verifyResponse->assertOk()
            ->assertJsonPath('data.isValid', false)
            ->assertJsonPath('data.status', 'revoked');
    }

    public function test_anti_idor_prevents_teacher_from_revoking_another_teachers_certificate(): void
    {
        $teacherA = $this->createTeacher('cert.teacherA@motafawweq.com');
        $teacherB = $this->createTeacher('cert.teacherB@motafawweq.com');

        $certA = CourseCertificate::create([
            'teacher_id'      => $teacherA->id,
            'student_name_ar' => 'كريم بدوي',
            'course_name_ar'  => 'أحياء',
            'grade_percent'   => 95.0,
            'honors_title_ar' => 'شهادة تفوق',
            'issue_date'      => now()->toDateString(),
            'status'          => CertificateStatus::VERIFIED,
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->patchJson("/api/v1/teacher/certificates/{$certA->uuid}/revoke", [
            'reason' => 'محاولة إلغاء غير مصرح بها',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.idor_attempt_blocked',
        ]);
    }

    /* ----------------------------------------------------------------- */
    /* Financials & Direct Student Payments Tests                         */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_fetch_financial_overview_and_direct_payments(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('يوسف الشناوي', 'std.pay@motafawweq.com');

        StudentDirectPayment::create([
            'teacher_id'        => $teacher->id,
            'student_id'        => $student->id,
            'amount_egp'        => 450.00,
            'payment_method'    => PaymentMethod::INSTAPAY,
            'student_name_ar'   => 'يوسف الشناوي',
            'course_name_ar'    => 'ماستر كلاس الوراثة الجزيئية',
            'status'            => DirectPaymentStatus::CONFIRMED,
            'paid_at'           => now()->subDay(),
            'confirmed_at'      => now(),
        ]);

        StudentDirectPayment::create([
            'teacher_id'        => $teacher->id,
            'student_id'        => $student->id,
            'amount_egp'        => 300.00,
            'payment_method'    => PaymentMethod::VODAFONE,
            'student_name_ar'   => 'يوسف الشناوي',
            'course_name_ar'    => 'كورس المراجعة الشاملة',
            'status'            => DirectPaymentStatus::PENDING,
            'paid_at'           => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/financials');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.earnings.totalRevenueEgp', 450)
            ->assertJsonPath('data.earnings.confirmedStudentsCount', 1)
            ->assertJsonCount(2, 'data.transactions');
    }

    public function test_teacher_can_confirm_and_reject_student_direct_payment(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('سلمى محمود', 'std.pay2@motafawweq.com');
        $group = StudyGroup::create([
            'teacher_id'  => $teacher->id,
            'name_ar'     => 'مجموعة النخبة الدقي',
            'center_name' => 'سنتر الدقي النخبة',
            'subject_ar'  => 'الأحياء',
            'grade_ar'    => 'الصف الثالث الثانوي',
            'price_egp'   => 450.00,
        ]);

        $payment = StudentDirectPayment::create([
            'teacher_id'        => $teacher->id,
            'student_id'        => $student->id,
            'study_group_id'    => $group->id,
            'amount_egp'        => 450.00,
            'payment_method'    => PaymentMethod::VODAFONE,
            'student_name_ar'   => 'سلمى محمود',
            'course_name_ar'    => 'مجموعة النخبة الدقي',
            'status'            => DirectPaymentStatus::PENDING,
            'paid_at'           => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Confirm payment
        $confirmResponse = $this->postJson("/api/v1/teacher/financials/payments/{$payment->uuid}/confirm");

        $confirmResponse->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('student_direct_payments', [
            'id'     => $payment->id,
            'status' => DirectPaymentStatus::CONFIRMED->value,
        ]);

        // Student automatically enrolled in group
        $this->assertDatabaseHas('group_enrollments', [
            'study_group_id' => $group->id,
            'student_id'     => $student->id,
            'status'         => 'enrolled',
        ]);

        // 2. Reject payment test
        $payment2 = StudentDirectPayment::create([
            'teacher_id'        => $teacher->id,
            'student_id'        => $student->id,
            'amount_egp'        => 200.00,
            'payment_method'    => PaymentMethod::CIB,
            'student_name_ar'   => 'سلمى محمود',
            'course_name_ar'    => 'كورس كيمياء حيوية',
            'status'            => DirectPaymentStatus::PENDING,
            'paid_at'           => now(),
        ]);

        $rejectResponse = $this->postJson("/api/v1/teacher/financials/payments/{$payment2->uuid}/reject", [
            'reason' => 'رقم الحوالة غير مطابق للإيصال المرفق',
        ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('student_direct_payments', [
            'id'     => $payment2->id,
            'status' => DirectPaymentStatus::REJECTED->value,
        ]);
    }

    public function test_anti_idor_prevents_teacher_from_confirming_another_teachers_payment(): void
    {
        $teacherA = $this->createTeacher('pay.teacherA@motafawweq.com');
        $teacherB = $this->createTeacher('pay.teacherB@motafawweq.com');
        $student = $this->createStudent('طالب دفع', 'std.pay3@motafawweq.com');

        $payment = StudentDirectPayment::create([
            'teacher_id'        => $teacherA->id,
            'student_id'        => $student->id,
            'amount_egp'        => 350.00,
            'payment_method'    => PaymentMethod::INSTAPAY,
            'student_name_ar'   => 'طالب دفع',
            'course_name_ar'    => 'كورس أحياء',
            'status'            => DirectPaymentStatus::PENDING,
            'paid_at'           => now(),
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/financials/payments/{$payment->uuid}/confirm");

        $response->assertNotFound();

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.idor_attempt_blocked',
        ]);
    }

    /* ----------------------------------------------------------------- */
    /* Teacher Billing, Coupons & Invoices Tests                         */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_fetch_billing_summary_and_validate_coupon(): void
    {
        $teacher = $this->createTeacher();

        DiscountCoupon::create([
            'code'             => 'PROMO50',
            'discount_percent' => 50,
            'is_active'        => true,
            'max_uses'         => 10,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Fetch billing summary
        $response = $this->getJson('/api/v1/teacher/billing');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.activeSub.status', 'active');

        // 2. Validate custom coupon
        $couponResponse = $this->postJson('/api/v1/teacher/billing/validate-coupon', [
            'code'         => 'PROMO50',
            'order_amount' => 480.0,
        ]);

        $couponResponse->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.discountPercent', 50)
            ->assertJsonPath('data.discountAmount', 240);

        // 3. Validate platform coupon (TEACHER2026)
        $teacher2026Response = $this->postJson('/api/v1/teacher/billing/validate-coupon', [
            'code'         => 'TEACHER2026',
            'order_amount' => 480.0,
        ]);

        $teacher2026Response->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.discountPercent', 25)
            ->assertJsonPath('data.discountAmount', 120);

        // 4. Invalid coupon returns validation error
        $invalidCouponResponse = $this->postJson('/api/v1/teacher/billing/validate-coupon', [
            'code' => 'INVALID_CODE',
        ]);

        $invalidCouponResponse->assertUnprocessable();
    }

    public function test_teacher_can_upgrade_plan_with_coupon_generating_official_invoice(): void
    {
        $teacher = $this->createTeacher();

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'plan_tier'      => TeacherPlanTier::PRO->value,
            'billing_period' => BillingPeriod::TERM->value, // 880 EGP base
            'payment_method' => PaymentMethod::VODAFONE->value,
            'coupon_code'    => 'TEACHER2026', // 25% discount -> 660 EGP
        ];

        $response = $this->postJson('/api/v1/teacher/billing/subscribe', $payload);

        $response->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.amountEgp', 660)
            ->assertJsonPath('data.invoice.baseAmount', 880)
            ->assertJsonPath('data.invoice.discountAmount', 220)
            ->assertJsonPath('data.invoice.finalAmount', 660)
            ->assertJsonPath('data.invoice.status', 'paid');

        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_id' => $teacher->id,
            'plan_tier'  => TeacherPlanTier::PRO->value,
            'status'     => 'active',
            'amount_egp' => 660,
        ]);

        $this->assertDatabaseHas('teacher_profiles', [
            'user_id'   => $teacher->id,
            'plan_tier' => TeacherPlanTier::PRO->value,
        ]);

        $this->assertDatabaseHas('teacher_invoices', [
            'teacher_id'       => $teacher->id,
            'final_amount_egp' => 660,
            'status'           => 'paid',
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.plan_subscribed',
        ]);
    }
}
