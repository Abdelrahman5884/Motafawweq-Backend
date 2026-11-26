<?php

namespace Tests\Feature\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\CertificateStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\LeagueDuration;
use App\Enums\LessonStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\DiscountCoupon;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentDirectPayment;
use App\Models\StudentLessonProgress;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherCertificatesAndFinancialsTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(
        string $email = 'teacher.cert@motafawweq.com',
        TeacherPlanTier $planTier = TeacherPlanTier::PLUS
    ): User {
        $teacher = User::factory()->create([
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $profile = TeacherProfile::create([
            'user_id' => $teacher->id,
            'name_ar' => $teacher->name,
            'name_en' => 'Dr. Teacher Example',
            'title_ar' => 'كبير معلمي الأحياء ومؤلف سلسلة التفوق',
            'subject_ar' => 'الأحياء',
        ]);
        $profile->forceFill(['plan_tier' => $planTier])->save();

        return $teacher;
    }

    protected function createStudent(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    /* ----------------------------------------------------------------- */
    /* Certificates Tests */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_issue_accredited_certificate_with_qr_and_digital_signature(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('عمر طارق القاضي', 'std.cert1@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'student_id' => $student->id,
            'student_name_ar' => 'عمر طارق القاضي',
            'student_name_en' => 'Omar Tarek',
            'student_email' => 'std.cert1@motafawweq.com',
            'course_name_ar' => 'ماستر كلاس الأحياء: البناء الضوئي وحركية الطاقة',
            'grade_ar' => 'الصف الثالث الثانوي',
            'grade_percent' => 98.5,
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
            'teacher_id' => $teacher->id,
            'certificate_number' => $certNumber,
            'status' => CertificateStatus::VERIFIED->value,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.certificate_issued',
        ]);
    }

    public function test_public_can_verify_certificate_via_qr_serial_number_and_detect_tampering(): void
    {
        $teacher = $this->createTeacher();
        $cert = CourseCertificate::create([
            'teacher_id' => $teacher->id,
            'student_name_ar' => 'سارة خالد منصور',
            'course_name_ar' => 'الأحياء للثانوية العامة',
            'grade_percent' => 99.0,
            'honors_title_ar' => 'شهادة تفوق وتميز',
            'issue_date' => now()->toDateString(),
            'status' => CertificateStatus::VERIFIED,
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
            'teacher_id' => $teacher->id,
            'student_name_ar' => 'مريم عادل',
            'course_name_ar' => 'أحياء الصف الثالث الثانوي',
            'grade_percent' => 92.0,
            'honors_title_ar' => 'شهادة إتمام',
            'issue_date' => now()->toDateString(),
            'status' => CertificateStatus::VERIFIED,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->patchJson("/api/v1/teacher/certificates/{$cert->uuid}/revoke", [
            'reason' => 'تم اكتشاف غش في الاختبار النهائي للمقرر',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revocationReason', 'تم اكتشاف غش في الاختبار النهائي للمقرر');

        $this->assertDatabaseHas('course_certificates', [
            'id' => $cert->id,
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
            'teacher_id' => $teacherA->id,
            'student_name_ar' => 'كريم بدوي',
            'course_name_ar' => 'أحياء',
            'grade_percent' => 95.0,
            'honors_title_ar' => 'شهادة تفوق',
            'issue_date' => now()->toDateString(),
            'status' => CertificateStatus::VERIFIED,
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

    public function test_teacher_can_bulk_issue_filter_with_stats_and_cannot_double_revoke(): void
    {
        $teacher = $this->createTeacher('cert.bulk@motafawweq.com');
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الفيزياء الحديثة',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);
        $std1 = $this->createStudent('ياسمين علي', 'yasmin.cert@motafawweq.com');
        $std2 = $this->createStudent('خالد حسن', 'khaled.cert@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Bulk issue with string IDs
        $bulkRes = $this->postJson('/api/v1/teacher/certificates/bulk-issue', [
            'course_id' => $course->uuid,
            'course_name_ar' => $course->title_ar,
            'grade_ar' => 'الصف الثالث الثانوي',
            'honors_title_ar' => 'شهادة تميز وتفوق بالدرجة النهائية (Full Mark)',
            'students' => [
                [
                    'student_id' => (string) $std1->id,
                    'student_name_ar' => $std1->name,
                    'grade_percent' => 100,
                ],
                [
                    'student_id' => (string) $std2->id,
                    'student_name_ar' => $std2->name,
                    'grade_percent' => 94,
                ],
            ],
        ]);

        $bulkRes->assertCreated()
            ->assertJsonCount(2, 'data');

        $firstUuid = $bulkRes->json('data.0.id');

        // Revoke first certificate
        $this->patchJson("/api/v1/teacher/certificates/{$firstUuid}/revoke", [
            'reason' => 'تصحيح خطأ في الدرجة',
        ])->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        // Re-revoking already revoked certificate must return 422
        $this->patchJson("/api/v1/teacher/certificates/{$firstUuid}/revoke", [
            'reason' => 'محاولة تكرار',
        ])->assertUnprocessable();

        // List with stats & course UUID filter
        $listRes = $this->getJson("/api/v1/teacher/certificates?course_id={$course->uuid}&status=verified");
        $listRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.stats.total', 2)
            ->assertJsonPath('meta.stats.verifiedCount', 1)
            ->assertJsonPath('meta.stats.revokedCount', 1);
    }

    public function test_teacher_certificate_enforces_100_percent_course_completion_and_concluded_league(): void
    {
        $teacher = $this->createTeacher('cert.strict@motafawweq.com');
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الكيمياء العضوية',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);
        $lesson1 = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدرس الأول',
            'sort_order' => 1,
            'status' => LessonStatus::PUBLISHED,
        ]);
        $lesson2 = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدرس الثاني',
            'sort_order' => 2,
            'status' => LessonStatus::PUBLISHED,
        ]);

        $student = $this->createStudent('ندى طارق', 'nada.strict@motafawweq.com');

        // Only 1/2 lessons completed (50%)
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson1->id,
            'is_completed' => true,
            'watched_seconds' => 300,
            'completed_at' => now()->subHour(),
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الكيمياء الشهري',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDays(27),
        ]);
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 260,
            'tier' => 'Diamond',
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Course completion certificate MUST be blocked while student is at 50%
        $this->postJson('/api/v1/teacher/certificates', [
            'certificate_type' => 'course_completion',
            'course_id' => $course->uuid,
            'student_id' => (string) $student->id,
            'student_name_ar' => $student->name,
            'course_name_ar' => $course->title_ar,
            'grade_percent' => 98,
            'honors_title_ar' => 'شهادة إتمام الكورس بمرتبة الشرف',
        ])->assertUnprocessable();

        // 2. League certificate MUST be blocked while league is still active
        $this->postJson('/api/v1/teacher/certificates', [
            'certificate_type' => 'league',
            'league_id' => $league->uuid,
            'course_id' => $course->uuid,
            'student_id' => (string) $student->id,
            'student_name_ar' => $student->name,
            'course_name_ar' => $league->title_ar,
            'grade_percent' => 100,
            'honors_title_ar' => 'شهادة صدارة وبطل الدوري الأكاديمي',
        ])->assertUnprocessable();

        // 3. Complete 2nd lesson (100%) -> course completion certificate succeeds!
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson2->id,
            'is_completed' => true,
            'watched_seconds' => 350,
            'completed_at' => now(),
        ]);

        // Check eligibility-context reports 100% eligible
        $ctxRes = $this->getJson("/api/v1/teacher/certificates/eligibility-context?course_id={$course->uuid}");
        $ctxRes->assertOk()
            ->assertJsonPath("data.courseCompletion.byStudent.{$student->id}.percent", 100)
            ->assertJsonPath("data.courseCompletion.byStudent.{$student->id}.eligible", true)
            ->assertJsonPath('data.leagues.0.isConcluded', false);

        $this->postJson('/api/v1/teacher/certificates', [
            'certificate_type' => 'course_completion',
            'course_id' => $course->uuid,
            'student_id' => (string) $student->id,
            'student_name_ar' => $student->name,
            'course_name_ar' => $course->title_ar,
            'grade_percent' => 98,
            'honors_title_ar' => 'شهادة إتمام الكورس بمرتبة الشرف',
        ])->assertCreated();

        // 4. Crown the league -> stops league and unlocks league certificate!
        $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/crown")
            ->assertOk()
            ->assertJsonPath('data.isCrowned', true);

        $this->postJson('/api/v1/teacher/certificates', [
            'certificate_type' => 'league',
            'league_id' => $league->uuid,
            'course_id' => $course->uuid,
            'student_id' => (string) $student->id,
            'student_name_ar' => $student->name,
            'course_name_ar' => $league->title_ar,
            'grade_percent' => 100,
            'honors_title_ar' => 'شهادة صدارة وبطل الدوري الأكاديمي',
        ])->assertCreated()
            ->assertJsonPath('data.issuerType', 'league');
    }

    /* ----------------------------------------------------------------- */
    /* Financials & Direct Student Payments Tests */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_fetch_financial_overview_and_direct_payments(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('يوسف الشناوي', 'std.pay@motafawweq.com');

        StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'amount_egp' => 450.00,
            'payment_method' => PaymentMethod::INSTAPAY,
            'student_name_ar' => 'يوسف الشناوي',
            'course_name_ar' => 'ماستر كلاس الوراثة الجزيئية',
            'status' => DirectPaymentStatus::CONFIRMED,
            'paid_at' => now()->subDay(),
            'confirmed_at' => now(),
        ]);

        StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'amount_egp' => 300.00,
            'payment_method' => PaymentMethod::VODAFONE,
            'student_name_ar' => 'يوسف الشناوي',
            'course_name_ar' => 'كورس المراجعة الشاملة',
            'status' => DirectPaymentStatus::PENDING,
            'paid_at' => now(),
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
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة النخبة الدقي',
            'center_name' => 'سنتر الدقي النخبة',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 450.00,
        ]);

        $payment = StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'study_group_id' => $group->id,
            'amount_egp' => 450.00,
            'payment_method' => PaymentMethod::VODAFONE,
            'student_name_ar' => 'سلمى محمود',
            'course_name_ar' => 'مجموعة النخبة الدقي',
            'status' => DirectPaymentStatus::PENDING,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Confirm payment
        $confirmResponse = $this->postJson("/api/v1/teacher/financials/payments/{$payment->uuid}/confirm");

        $confirmResponse->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseHas('student_direct_payments', [
            'id' => $payment->id,
            'status' => DirectPaymentStatus::CONFIRMED->value,
        ]);

        // Student automatically enrolled in group
        $this->assertDatabaseHas('group_enrollments', [
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => 'enrolled',
        ]);

        // 2. Reject payment test
        $payment2 = StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'amount_egp' => 200.00,
            'payment_method' => PaymentMethod::CIB,
            'student_name_ar' => 'سلمى محمود',
            'course_name_ar' => 'كورس كيمياء حيوية',
            'status' => DirectPaymentStatus::PENDING,
            'paid_at' => now(),
        ]);

        $rejectResponse = $this->postJson("/api/v1/teacher/financials/payments/{$payment2->uuid}/reject", [
            'reason' => 'رقم الحوالة غير مطابق للإيصال المرفق',
        ]);

        $rejectResponse->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('student_direct_payments', [
            'id' => $payment2->id,
            'status' => DirectPaymentStatus::REJECTED->value,
        ]);
    }

    public function test_anti_idor_prevents_teacher_from_confirming_another_teachers_payment(): void
    {
        $teacherA = $this->createTeacher('pay.teacherA@motafawweq.com');
        $teacherB = $this->createTeacher('pay.teacherB@motafawweq.com');
        $student = $this->createStudent('طالب دفع', 'std.pay3@motafawweq.com');

        $payment = StudentDirectPayment::create([
            'teacher_id' => $teacherA->id,
            'student_id' => $student->id,
            'amount_egp' => 350.00,
            'payment_method' => PaymentMethod::INSTAPAY,
            'student_name_ar' => 'طالب دفع',
            'course_name_ar' => 'كورس أحياء',
            'status' => DirectPaymentStatus::PENDING,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/financials/payments/{$payment->uuid}/confirm");

        $response->assertNotFound();

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.idor_attempt_blocked',
        ]);
    }

    /* ----------------------------------------------------------------- */
    /* Teacher Billing, Coupons & Invoices Tests */
    /* ----------------------------------------------------------------- */

    public function test_teacher_can_fetch_billing_summary_and_validate_coupon(): void
    {
        $teacher = $this->createTeacher();

        DiscountCoupon::create([
            'code' => 'PROMO50',
            'discount_percent' => 50,
            'is_active' => true,
            'max_uses' => 10,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Fetch billing summary
        $response = $this->getJson('/api/v1/teacher/billing');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.activeSub.status', 'active');

        // 2. Validate custom coupon
        $couponResponse = $this->postJson('/api/v1/teacher/billing/validate-coupon', [
            'code' => 'PROMO50',
            'order_amount' => 480.0,
        ]);

        $couponResponse->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.discountPercent', 50)
            ->assertJsonPath('data.discountAmount', 240);

        // 3. Validate platform coupon (TEACHER2026)
        $teacher2026Response = $this->postJson('/api/v1/teacher/billing/validate-coupon', [
            'code' => 'TEACHER2026',
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
        Storage::fake('local');
        $teacher = $this->createTeacher('free.upgrade@motafawweq.com', TeacherPlanTier::FREE);
        $admin = User::factory()->admin()->create([
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $payload = [
            'plan_tier' => TeacherPlanTier::PRO->value,
            'billing_period' => BillingPeriod::TERM->value, // 600 EGP base (150/mo * 5 - 20%)
            'payment_method' => PaymentMethod::VODAFONE->value,
            'coupon_code' => 'TEACHER2026', // 25% discount -> 450 EGP
            'sender_phone' => '01012345678',
            'transaction_ref' => 'VF-PRO-TERM-450',
            'receipt' => UploadedFile::fake()->createWithContent('pro-term.png', $pngBytes),
        ];

        $response = $this->post('/api/v1/teacher/billing/subscribe', $payload, ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.requiresApproval', true)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.invoice.baseAmount', 600)
            ->assertJsonPath('data.invoice.discountAmount', 150)
            ->assertJsonPath('data.invoice.finalAmount', 450)
            ->assertJsonPath('data.invoice.status', 'pending');

        $pendingUuid = (string) $response->json('data.pendingRequest.id');

        // Super Admin approves -> Pro activates and invoice transitions to paid
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/admin/financials/subscription-requests/teacher/{$pendingUuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_id' => $teacher->id,
            'plan_tier' => TeacherPlanTier::PRO->value,
            'status' => 'active',
            'amount_egp' => 450,
        ]);

        $this->assertDatabaseHas('teacher_profiles', [
            'user_id' => $teacher->id,
            'plan_tier' => TeacherPlanTier::PRO->value,
        ]);

        $this->assertDatabaseHas('teacher_invoices', [
            'teacher_id' => $teacher->id,
            'final_amount_egp' => 450,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'teacher.subscription.submitted_for_review',
        ]);
        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'admin.subscription.approved',
        ]);
    }
}
