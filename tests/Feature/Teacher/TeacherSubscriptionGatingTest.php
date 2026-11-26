<?php

namespace Tests\Feature\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\PlatformSubscriptionPlan;
use App\Models\StudyGroup;
use App\Models\TeacherSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSubscriptionGatingTest extends TestCase
{
    use RefreshDatabase;

    private function createTeacher(string $email = 'gated.teacher@motafawweq.me'): User
    {
        return User::factory()->create([
            'name' => 'د. أحمد منصور',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function createSuperAdmin(string $email = 'admin.teacher.bill@motafawweq.me'): User
    {
        return User::factory()->admin()->create([
            'email' => $email,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function fakeReceiptPng(string $name = 'receipt.png'): UploadedFile
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $pngBytes);
    }

    public function test_new_teacher_defaults_to_free_with_exact_pricing_catalog_and_no_fabricated_subscription(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // /auth/me exposes free tier, locked features, and Free quotas
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.plan_tier', 'free')
            ->assertJsonPath('data.unlocked_features.schedule', false)
            ->assertJsonPath('data.unlocked_features.exams', false)
            ->assertJsonPath('data.unlocked_features.ai', false)
            ->assertJsonPath('data.plan_quotas.maxCourses', 1)
            ->assertJsonPath('data.plan_quotas.maxLessons', 3)
            ->assertJsonPath('data.plan_quotas.maxGroups', 1)
            ->assertJsonPath('data.plan_quotas.maxStudentsPerGroup', 50)
            ->assertJsonPath('data.plan_quotas.allowsAi', false);

        // /teacher/billing returns Free activeSub without creating a fake Plus row in DB
        $this->getJson('/api/v1/teacher/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.activeSub.isFree', true)
            ->assertJsonPath('data.activeSub.canCancel', false)
            ->assertJsonPath('data.activeSub.mustCancelToSwitch', false)
            ->assertJsonPath('data.pricingMatrix.free.monthly', 0)
            ->assertJsonPath('data.pricingMatrix.plus.monthly', 120)
            ->assertJsonPath('data.pricingMatrix.plus.term', 480)
            ->assertJsonPath('data.pricingMatrix.plus.annual', 900)
            ->assertJsonPath('data.pricingMatrix.pro.monthly', 150)
            ->assertJsonPath('data.pricingMatrix.pro.term', 600)
            ->assertJsonPath('data.pricingMatrix.pro.annual', 1125)
            ->assertJsonCount(3, 'data.plans');

        $this->assertDatabaseCount('teacher_subscriptions', 0);
    }

    public function test_free_teacher_quotas_and_feature_locks_are_strictly_enforced(): void
    {
        $teacher = $this->createTeacher('free.quotas@motafawweq.me');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Course quota: 1 allowed, 2nd blocked (403 PLAN_QUOTA_EXCEEDED -> Plus)
        $course1Res = $this->postJson('/api/v1/teacher/courses', [
            'title' => 'الكورس المجاني الأول',
            'stage' => 'sec',
            'grade' => 'الصف الثالث الثانوي',
            'price' => 250,
        ])->assertCreated();
        $courseUuid = $course1Res->json('data.id');

        $this->postJson('/api/v1/teacher/courses', [
            'title' => 'الكورس الثاني الممنوع',
            'stage' => 'sec',
            'grade' => 'الصف الثالث الثانوي',
            'price' => 250,
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'plus')
            ->assertJsonPath('feature', 'courses')
            ->assertJsonPath('limit', 1);

        // 2. Lesson quota: 3 allowed (AI forced OFF), 4th blocked (403 PLAN_QUOTA_EXCEEDED -> Plus)
        $firstLessonUuid = null;
        for ($i = 1; $i <= 3; $i++) {
            $res = $this->postJson('/api/v1/teacher/lessons', [
                'title_ar' => "الحصة المجانية رقم {$i}",
                'course_uuid' => $courseUuid,
                'media_mode' => 'video',
                'ai_features_requested' => [
                    'speechToText' => true,
                    'conceptGraph' => true,
                    'chapterIndexing' => true,
                ],
            ])->assertCreated();

            $this->assertFalse($res->json('data.aiFeatures.speechToText'));
            $this->assertFalse($res->json('data.aiFeatures.conceptGraph'));
            $this->assertFalse($res->json('data.aiFeatures.chapterIndexing'));

            if ($i === 1) {
                $firstLessonUuid = $res->json('data.id');
            }
        }

        $this->postJson('/api/v1/teacher/lessons', [
            'title_ar' => 'الحصة الرابعة الممنوعة',
            'course_uuid' => $courseUuid,
            'media_mode' => 'video',
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'plus')
            ->assertJsonPath('feature', 'lessons')
            ->assertJsonPath('limit', 3);

        // 3. Group capacity <= 50 & max 1 group on Free
        $this->postJson('/api/v1/teacher/groups', [
            'nameAr' => 'مجموعة أكبر من 50 طالب',
            'subjectAr' => 'الأحياء',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر الدقي',
            'maxStudents' => 51,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['max_students']);

        $groupRes = $this->postJson('/api/v1/teacher/groups', [
            'nameAr' => 'المجموعة المجانية الوحيدة',
            'subjectAr' => 'الأحياء',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر الدقي',
            'maxStudents' => 50,
            'scheduleSlots' => [
                ['day' => DayOfWeek::SUNDAY->value, 'startTime' => '16:00', 'endTime' => '18:00'],
            ],
        ])->assertCreated();
        $groupUuid = $groupRes->json('data.id');

        // Updating capacity above 50 on Free is rejected
        $this->putJson("/api/v1/teacher/groups/{$groupUuid}", [
            'maxStudents' => 60,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['max_students']);

        // Creating a 2nd group on Free is blocked (403 PLAN_QUOTA_EXCEEDED -> Plus)
        $this->postJson('/api/v1/teacher/groups', [
            'nameAr' => 'المجموعة الثانية الممنوعة',
            'subjectAr' => 'الأحياء',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر المعادي',
            'maxStudents' => 30,
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'plus')
            ->assertJsonPath('feature', 'groups')
            ->assertJsonPath('limit', 1);

        // 4. Plus-gated endpoints are locked (403 SUBSCRIPTION_REQUIRED -> Plus)
        foreach ([
            '/api/v1/teacher/schedule',
            '/api/v1/teacher/exams',
            '/api/v1/teacher/homeworks',
            '/api/v1/teacher/leagues',
            '/api/v1/teacher/certificates',
            '/api/v1/teacher/financials',
            '/api/v1/teacher/payout-accounts',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
                ->assertJsonPath('current_plan', 'free')
                ->assertJsonPath('required_plan', 'plus')
                ->assertJsonPath('required_plan_name_ar', TeacherPlanTier::PLUS->labelAr());
        }

        // 5. Pro AI workspace mutations are locked (403 SUBSCRIPTION_REQUIRED -> Pro)
        $this->putJson("/api/v1/teacher/workspace/lessons/{$firstLessonUuid}/transcript", [
            'transcript' => [
                ['startSeconds' => 0, 'textAr' => 'مرحباً بكم'],
            ],
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'pro');

        $this->putJson("/api/v1/teacher/workspace/lessons/{$firstLessonUuid}/concept-graph", [
            'tree_data' => ['id' => 'root', 'label' => 'الأحياء', 'children' => []],
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'pro');
    }

    public function test_plus_unlocks_12_groups_unlimited_courses_and_lessons_and_schedule_without_ai(): void
    {
        Storage::fake('local');
        $teacher = $this->createTeacher('plus.teacher@motafawweq.me');
        $admin = $this->createSuperAdmin('admin.plus.t@motafawweq.me');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Submit paid Free -> Plus (120 EGP/mo) with receipt + transaction_ref -> enters pending_review first
        $subRes = $this->post('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PLUS->value,
            'billing_period' => BillingPeriod::MONTHLY->value,
            'payment_method' => PaymentMethod::VODAFONE->value,
            'sender_phone' => '01012345678',
            'transaction_ref' => 'VF-T-PLUS-120',
            'receipt' => $this->fakeReceiptPng('teacher-plus.png'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.requiresApproval', true)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.invoice.finalAmount', 120);

        // Schedule remains locked until Super Admin approves!
        $this->getJson('/api/v1/teacher/schedule')->assertForbidden();

        // Super Admin approves -> Plus unlocks immediately
        $pendingUuid = (string) $subRes->json('data.pendingRequest.id');
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/admin/financials/subscription-requests/teacher/{$pendingUuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/teacher/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'plus')
            ->assertJsonPath('data.activeSub.amountEgp', 120)
            ->assertJsonPath('data.activeSub.canCancel', true)
            ->assertJsonPath('data.activeSub.mustCancelToSwitch', true);

        // Plus features now succeed
        $this->getJson('/api/v1/teacher/schedule')->assertOk();
        $this->getJson('/api/v1/teacher/exams')->assertOk();
        $this->getJson('/api/v1/teacher/homeworks')->assertOk();
        $this->getJson('/api/v1/teacher/leagues')->assertOk();
        $this->getJson('/api/v1/teacher/certificates')->assertOk();
        $this->getJson('/api/v1/teacher/financials')->assertOk();

        // Unlimited courses & lessons
        $c1 = Course::create(['teacher_id' => $teacher->id, 'title_ar' => 'كورس 1', 'stage_id' => 'sec', 'grade_ar' => '3 ثانوي']);
        $this->postJson('/api/v1/teacher/courses', [
            'title' => 'كورس 2 في بلس',
            'stage' => 'sec',
            'grade' => 'الصف الثالث الثانوي',
            'price' => 300,
        ])->assertCreated();

        for ($i = 1; $i <= 3; $i++) {
            Lesson::create(['teacher_id' => $teacher->id, 'course_id' => $c1->id, 'title_ar' => "حصة {$i}"]);
        }
        $lesson4Res = $this->postJson('/api/v1/teacher/lessons', [
            'title_ar' => 'الحصة الرابعة في بلس (بدون AI)',
            'course_uuid' => $c1->uuid,
            'media_mode' => 'video',
            'ai_features_requested' => ['speechToText' => true, 'conceptGraph' => true, 'chapterIndexing' => true],
        ])->assertCreated();
        $lesson4Uuid = $lesson4Res->json('data.id');

        // AI MUST still be disabled on Plus
        $this->assertFalse($lesson4Res->json('data.aiFeatures.speechToText'));
        $this->assertFalse($lesson4Res->json('data.aiFeatures.conceptGraph'));

        $this->putJson("/api/v1/teacher/workspace/lessons/{$lesson4Uuid}/transcript", [
            'transcript' => [['startSeconds' => 0, 'textAr' => 'اختبار']],
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'plus')
            ->assertJsonPath('required_plan', 'pro');

        // Study groups: 12 allowed (with >50 students allowed), 13th blocked (required_plan: 'pro')
        for ($g = 1; $g <= 11; $g++) {
            StudyGroup::create([
                'teacher_id' => $teacher->id,
                'name_ar' => "مجموعة بلس {$g}",
                'subject_ar' => 'الأحياء',
                'grade_ar' => 'الصف الثالث الثانوي',
                'center_name' => 'سنتر الدقي',
                'max_students' => 120,
                'status' => 'active',
            ]);
        }

        // 12th group succeeds with 150 maxStudents
        $this->postJson('/api/v1/teacher/groups', [
            'nameAr' => 'المجموعة رقم 12 في بلس',
            'subjectAr' => 'الأحياء',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر الدقي',
            'maxStudents' => 150,
        ])->assertCreated();

        // 13th group blocked -> requires Pro
        $this->postJson('/api/v1/teacher/groups', [
            'nameAr' => 'المجموعة رقم 13 فوق حد بلس',
            'subjectAr' => 'الأحياء',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر الدقي',
            'maxStudents' => 100,
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('current_plan', 'plus')
            ->assertJsonPath('required_plan', 'pro')
            ->assertJsonPath('feature', 'groups')
            ->assertJsonPath('limit', 12);
    }

    public function test_must_cancel_active_subscription_before_switching_to_pro_and_expiry_relocks(): void
    {
        Storage::fake('local');
        $teacher = $this->createTeacher('switch.teacher@motafawweq.me');
        $admin = $this->createSuperAdmin('admin.switch.t@motafawweq.me');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Cannot cancel when already on Free
        $this->postJson('/api/v1/teacher/billing/cancel')->assertUnprocessable();

        // 1. Subscribe to Plus (120 EGP/mo) with receipt + approve via Super Admin
        $plusRes = $this->post('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PLUS->value,
            'billing_period' => BillingPeriod::MONTHLY->value,
            'payment_method' => PaymentMethod::INSTAPAY->value,
            'sender_ipa' => 'teacher@instapay',
            'transaction_ref' => 'IP-T-PLUS-01',
            'receipt' => $this->fakeReceiptPng('t-plus.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson('/api/v1/admin/financials/subscription-requests/teacher/'.$plusRes->json('data.pendingRequest.id').'/approve')
            ->assertOk();

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 2. Attempting direct switch to Pro (or Plus Annual) without cancelling MUST return 409 Conflict
        $this->post('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PRO->value,
            'billing_period' => BillingPeriod::MONTHLY->value,
            'payment_method' => PaymentMethod::INSTAPAY->value,
            'transaction_ref' => 'IP-T-PRO-BLK',
            'receipt' => $this->fakeReceiptPng('blk.png'),
        ], ['Accept' => 'application/json'])->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVE_SUBSCRIPTION_EXISTS')
            ->assertJsonPath('current_plan', 'plus');

        $this->post('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PLUS->value,
            'billing_period' => BillingPeriod::ANNUAL->value,
            'payment_method' => PaymentMethod::INSTAPAY->value,
            'transaction_ref' => 'IP-T-ANN-BLK',
            'receipt' => $this->fakeReceiptPng('blk2.png'),
        ], ['Accept' => 'application/json'])->assertStatus(409)
            ->assertJsonPath('error_code', 'ACTIVE_SUBSCRIPTION_EXISTS');

        // 3. Cancel active Plus subscription -> reverts immediately to Free and re-locks Plus features
        $this->postJson('/api/v1/teacher/billing/cancel')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'free')
            ->assertJsonPath('data.activeSub.isFree', true)
            ->assertJsonPath('data.previousPlan', 'plus');

        $this->assertDatabaseHas('teacher_subscriptions', [
            'teacher_id' => $teacher->id,
            'plan_tier' => TeacherPlanTier::PLUS->value,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event' => 'teacher.plan_cancelled',
        ]);

        // Schedule & Exams are immediately locked again
        $this->getJson('/api/v1/teacher/schedule')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'free');

        // 4. Now subscribing to Pro (150 EGP/mo) + Super Admin approval unlocks AI + unlimited groups
        $proRes = $this->post('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PRO->value,
            'billing_period' => BillingPeriod::MONTHLY->value,
            'payment_method' => PaymentMethod::VODAFONE->value,
            'sender_phone' => '01099887766',
            'transaction_ref' => 'VF-T-PRO-150',
            'receipt' => $this->fakeReceiptPng('t-pro.png'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.requiresApproval', true)
            ->assertJsonPath('data.invoice.finalAmount', 150);

        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson('/api/v1/admin/financials/subscription-requests/teacher/'.$proRes->json('data.pendingRequest.id').'/approve')
            ->assertOk();

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/teacher/billing')
            ->assertOk()
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.amountEgp', 150);

        $lessonRes = $this->postJson('/api/v1/teacher/lessons', [
            'title_ar' => 'حصة برو بالذكاء الاصطناعي',
            'media_mode' => 'video',
            'ai_features_requested' => [
                'speechToText' => true,
                'conceptGraph' => true,
                'chapterIndexing' => true,
            ],
        ])->assertCreated();

        $this->assertTrue($lessonRes->json('data.aiFeatures.speechToText'));
        $this->assertTrue($lessonRes->json('data.aiFeatures.conceptGraph'));
        $this->assertTrue($lessonRes->json('data.aiFeatures.chapterIndexing'));

        $proLessonUuid = $lessonRes->json('data.id');
        $this->putJson("/api/v1/teacher/workspace/lessons/{$proLessonUuid}/transcript", [
            'transcript' => [['startSeconds' => 0, 'textAr' => 'تفريغ نصي ذكي']],
        ])->assertOk();

        $this->putJson("/api/v1/teacher/workspace/lessons/{$proLessonUuid}/concept-graph", [
            'tree_data' => ['id' => 'root', 'label' => 'خريطة برو', 'children' => []],
        ])->assertOk();

        // 5. Expire the Pro subscription -> auto-downgrades to Free and re-locks immediately
        $proSub = TeacherSubscription::where('teacher_id', $teacher->id)
            ->where('status', 'active')
            ->firstOrFail();
        $proSub->update(['renews_at' => now()->subMinute()]);

        $this->putJson("/api/v1/teacher/workspace/lessons/{$proLessonUuid}/transcript", [
            'transcript' => [['startSeconds' => 0, 'textAr' => 'بعد انتهاء الاشتراك']],
        ])->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'pro');

        $this->getJson('/api/v1/teacher/schedule')
            ->assertForbidden()
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'plus');

        $this->assertSame('expired', $proSub->fresh()->status);
    }

    public function test_free_teacher_cannot_enroll_more_than_50_students(): void
    {
        $teacher = $this->createTeacher('free.50cap@motafawweq.me');
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة السعة القصوى',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'max_students' => 50,
            'join_code' => 'CAP50-FREE',
            'status' => 'active',
        ]);

        // Seed 50 enrolled students
        $students = User::factory()->count(50)->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        foreach ($students as $student) {
            GroupEnrollment::create([
                'study_group_id' => $group->id,
                'student_id' => $student->id,
                'status' => EnrollmentStatus::ENROLLED,
                'enrolled_at' => now(),
            ]);
        }

        // 51st student invite must fail validation (422)
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $extraStudent = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->postJson("/api/v1/teacher/groups/{$group->uuid}/invite", [
            'student_id' => (string) $extraStudent->id,
        ])->assertUnprocessable();
    }

    public function test_teacher_temporary_free_promo_activates_instantly_without_receipt(): void
    {
        $teacher = $this->createTeacher('promo.teacher@motafawweq.me');

        PlatformSubscriptionPlan::create([
            'slug' => 'plan-teacher-pro',
            'target_role' => 'teacher',
            'tier_key' => 'pro',
            'name_ar' => 'باقة المعلم برو (Pro)',
            'name_en' => 'Teacher Pro',
            'billing_cycle' => 'monthly',
            'price_egp' => 150,
            'term_price_egp' => 600,
            'annual_price_egp' => 1125,
            'is_active' => true,
            'free_promo_enabled' => true,
            'free_promo_until' => now()->addDays(10),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->postJson('/api/v1/teacher/billing/subscribe', [
            'plan_tier' => TeacherPlanTier::PRO->value,
            'billing_period' => BillingPeriod::MONTHLY->value,
            'payment_method' => PaymentMethod::VODAFONE->value,
        ])->assertOk()
            ->assertJsonPath('data.requiresApproval', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.activeSub.planTier', 'pro')
            ->assertJsonPath('data.activeSub.isFreePromo', true)
            ->assertJsonPath('data.activeSub.amountEgp', 0);

        $this->getJson('/api/v1/teacher/schedule')->assertOk();
    }
}
