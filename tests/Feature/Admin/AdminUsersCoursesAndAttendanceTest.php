<?php

namespace Tests\Feature\Admin;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\BillingPeriod;
use App\Enums\CenterPlanTier;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TeacherPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\Course;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\SecurityAuditLog;
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\TeacherSubscription;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class AdminUsersCoursesAndAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_admin_user_lifecycle_encrypted_national_id_kyc_verification_and_csv_safety(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['access']);

        // 1. Create a Teacher with National ID (auto-password + OTP notification + encrypted PII)
        $createRes = $this->postJson('/api/v1/admin/users', [
            'name' => '=CMD|calc!A0 حسام الفيزياء',
            'email' => 'hossam.physics@motafawweq.test',
            'phone' => '01511223344',
            'role' => 'teacher',
            'nationalId' => '29204011204812',
            'details' => 'معلم أول فيزياء',
            'degreeAr' => 'بكالوريوس علوم الفيزياء',
        ])->assertStatus(201);

        $teacherId = (int) $createRes->json('data.id');
        $this->assertEquals('pending_verification', $createRes->json('data.status'));
        $this->assertEquals('**********4812', $createRes->json('data.nationalIdMasked'));

        // Verify National ID is stored encrypted (NOT plaintext) in raw DB row
        $rawNid = DB::table('users')->where('id', $teacherId)->value('national_id_encrypted');
        $this->assertNotEmpty($rawNid);
        $this->assertNotEquals('29204011204812', $rawNid);

        // Verify password reset OTP was sent since password was auto-generated
        Notification::assertSentTo(User::find($teacherId), ResetPasswordOtpNotification::class);

        // 2. Exact search by National ID works via blind index!
        $searchRes = $this->getJson('/api/v1/admin/users?search=29204011204812&role=teacher')
            ->assertOk();
        $this->assertCount(1, $searchRes->json('data'));
        $this->assertEquals($teacherId, $searchRes->json('data.0.id'));
        $this->assertNull($searchRes->json('data.0.nationalId')); // hidden in list view
        $this->assertEquals('**********4812', $searchRes->json('data.0.nationalIdMasked'));

        // 3. Verification Queue lists pending teacher, and Approve verifies + activates account
        $queueRes = $this->getJson('/api/v1/admin/users/verifications')->assertOk();
        $this->assertTrue(collect($queueRes->json('data'))->contains('id', $teacherId));

        $this->postJson("/api/v1/admin/users/{$teacherId}/verify", [
            'notes' => 'تم التحقق من المؤهل الدراسي',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $profile = TeacherProfile::where('user_id', $teacherId)->first();
        $this->assertTrue($profile->is_verified_educator);
        $this->assertEquals(VerificationStatus::VERIFIED, $profile->verification_status);
        $this->assertEquals($admin->id, $profile->verified_by);

        // 4. Suspend immediately revokes all active tokens of target user
        $teacher = User::find($teacherId);
        $teacher->createToken('device-1', ['access']);
        $this->assertEquals(1, $teacher->tokens()->count());

        $this->patchJson("/api/v1/admin/users/{$teacherId}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertEquals(0, $teacher->fresh()->tokens()->count());

        // 5. Reject verification workflow
        $this->postJson("/api/v1/admin/users/{$teacherId}/reject-verification", [
            'reason' => 'مستند غير واضح',
        ])->assertOk();
        $this->assertEquals(VerificationStatus::REJECTED, $profile->fresh()->verification_status);

        // 6. Export CSV neutralizes formula injection (=CMD|calc!A0)
        $csvRes = $this->get('/api/v1/admin/users/export')->assertOk();
        $this->assertStringContainsString("\"'=CMD|calc!A0 حسام الفيزياء\"", $csvRes->getContent());

        // 7. Create a center_admin user via alias role mapping
        $centerRes = $this->postJson('/api/v1/admin/users', [
            'name' => 'سنتر الرواد',
            'email' => 'rowad@center.test',
            'role' => 'center_admin',
        ])->assertStatus(201);
        $this->assertEquals('center', $centerRes->json('data.role'));
        $this->assertDatabaseHas('center_profiles', ['user_id' => $centerRes->json('data.id')]);

        // 8. Show full user profile details & Delete user
        $this->getJson("/api/v1/admin/users/{$teacherId}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'email', 'activeSessionsCount', 'recentLogins', 'walletBalance'],
            ]);

        $this->deleteJson("/api/v1/admin/users/{$teacherId}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $teacherId]);
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.user.deleted')->exists());
    }

    public function test_admin_course_moderation_publish_feature_and_soft_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $teacher = User::factory()->create(['role' => UserRole::TEACHER]);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء الحديثة للثانوية',
            'grade_ar' => 'الصف الثالث الثانوي',
            'subject_ar' => 'الفيزياء',
            'price_egp' => 400,
            'status' => CourseStatus::DRAFT,
        ]);

        Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الحصة الأولى',
            'duration_seconds' => 1800,
            'status' => 'published',
        ]);

        Sanctum::actingAs($admin, ['access']);

        // List with stage & search filters
        $listRes = $this->getJson('/api/v1/admin/courses?search=الفيزياء&stage=الصف الثالث الثانوي')
            ->assertOk();
        $this->assertCount(1, $listRes->json('data'));
        $this->assertEquals('review', $listRes->json('data.0.status'));
        $this->assertEquals(1, $listRes->json('data.0.totalLectures'));

        // Toggle publish -> published
        $this->patchJson("/api/v1/admin/courses/{$course->uuid}/toggle-publish", [
            'notes' => 'مقرر مطابق للمعايير',
        ])->assertOk()
            ->assertJsonPath('data.status', 'published');
        $this->assertEquals(CourseStatus::PUBLISHED, $course->fresh()->status);

        // Toggle featured -> true
        $this->patchJson("/api/v1/admin/courses/{$course->uuid}/toggle-featured")
            ->assertOk()
            ->assertJsonPath('data.isFeatured', true);
        $this->assertTrue((bool) $course->fresh()->is_featured);

        // Show course detail includes lessons
        $this->getJson("/api/v1/admin/courses/{$course->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data.lessons');

        // Delete soft-deletes & archives course with audit log
        $this->deleteJson("/api/v1/admin/courses/{$course->uuid}", [
            'reason' => 'محتوى مخالف',
        ])->assertOk();

        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.course.deleted')->exists());
    }

    public function test_admin_global_center_gate_attendance_monitoring_and_csv_export(): void
    {
        $admin = User::factory()->admin()->create();
        $center = User::factory()->center()->create(['status' => UserStatus::ACTIVE]);
        CenterProfile::create([
            'user_id' => $center->id,
            'name_ar' => 'سنتر الرواد الدقي',
            'name_en' => 'Al-Rowad Dokki',
        ]);

        $studentPaid = User::factory()->create(['name' => 'أحمد الدسوقي', 'role' => UserRole::STUDENT]);
        $studentUnpaid = User::factory()->create(['name' => '+EVIL() كريم وائل', 'role' => UserRole::STUDENT]);

        $group = StudyGroup::create([
            'teacher_id' => $center->id,
            'center_id' => $center->id,
            'name_ar' => 'مجموعة الفيزياء',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الرواد الدقي',
            'hall_name' => 'قاعة نيوتن (1)',
            'status' => 'active',
        ]);

        $enrPaid = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $studentPaid->id,
            'status' => EnrollmentStatus::ENROLLED,
            'student_barcode' => 'MOTA-STU-3091',
            'payment_status' => 'paid',
            'due_amount_egp' => 0,
        ]);

        $enrDue = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $studentUnpaid->id,
            'status' => EnrollmentStatus::ENROLLED,
            'student_barcode' => 'MOTA-STU-8821',
            'payment_status' => 'due',
            'due_amount_egp' => 350,
        ]);

        $session = GroupAttendanceSession::create([
            'study_group_id' => $group->id,
            'teacher_id' => $center->id,
            'center_id' => $center->id,
            'session_date' => now()->toDateString(),
            'title_ar' => 'حصة اليوم',
            'status' => 'open',
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $session->id,
            'study_group_id' => $group->id,
            'group_enrollment_id' => $enrPaid->id,
            'student_id' => $studentPaid->id,
            'status' => AttendanceStatus::PRESENT,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => now(),
            'recorded_by' => $center->id,
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $session->id,
            'study_group_id' => $group->id,
            'group_enrollment_id' => $enrDue->id,
            'student_id' => $studentUnpaid->id,
            'status' => AttendanceStatus::ABSENT,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => now()->subMinute(),
            'recorded_by' => $center->id,
        ]);

        Sanctum::actingAs($admin, ['access']);

        $res = $this->getJson('/api/v1/admin/attendance/gates')->assertOk();
        $res->assertJsonPath('data.summary.totalScansToday', 2);
        $res->assertJsonPath('data.summary.authorizedEntriesToday', 1);
        $res->assertJsonPath('data.summary.deniedOrUnpaidCount', 1);
        $res->assertJsonPath('data.summary.connectedCentersCount', 1);
        $this->assertCount(2, $res->json('data.logs'));

        // Filter by barcode
        $filtered = $this->getJson('/api/v1/admin/attendance/gates?search=MOTA-STU-3091')->assertOk();
        $this->assertCount(1, $filtered->json('data.logs'));
        $this->assertEquals('granted', $filtered->json('data.logs.0.status'));

        // Filter by denied
        $denied = $this->getJson('/api/v1/admin/attendance/gates?status=denied')->assertOk();
        $this->assertCount(1, $denied->json('data.logs'));
        $this->assertEquals('denied', $denied->json('data.logs.0.status'));

        // Export CSV neutralizes formula characters
        $csvRes = $this->get('/api/v1/admin/attendance/gates/export')->assertOk();
        $this->assertStringContainsString("\"'+EVIL() كريم وائل\"", $csvRes->getContent());
    }

    public function test_admin_user_details_returns_full_subscription_history_and_native_excel_export_is_valid_and_safe(): void
    {
        $admin = User::factory()->admin()->create();

        // Create a student with BOTH an active Pro plan and an older expired Plus plan
        $student = User::factory()->create([
            'name' => '=HYPERLINK("http://evil.test","خالد الطالب")',
            'email' => 'khaled.subs@motafawweq.test',
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
        ]);

        StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PLUS->value,
            'plan_name_ar' => 'باقة الطالب بلس',
            'plan_name_en' => 'Student Plus',
            'billing_period' => BillingPeriod::MONTHLY->value,
            'amount_egp' => 150,
            'starts_at' => now()->subDays(60),
            'renews_at' => now()->subDays(30),
            'status' => 'expired',
        ]);

        StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PRO->value,
            'plan_name_ar' => 'باقة الطالب برو',
            'plan_name_en' => 'Student Pro',
            'billing_period' => BillingPeriod::ANNUAL->value,
            'amount_egp' => 1200,
            'starts_at' => now()->subDays(10),
            'renews_at' => now()->addDays(355),
            'status' => 'active',
        ]);

        // Create a teacher with an active Pro subscription
        $teacher = User::factory()->create([
            'name' => 'أ. محمود المعلم',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
        ]);
        TeacherSubscription::create([
            'teacher_id' => $teacher->id,
            'plan_tier' => TeacherPlanTier::PRO->value,
            'plan_name_ar' => 'باقة المعلم برو',
            'plan_name_en' => 'Teacher Pro',
            'billing_period' => BillingPeriod::MONTHLY->value,
            'amount_egp' => 599,
            'starts_at' => now()->subDays(5),
            'renews_at' => now()->addDays(25),
            'status' => 'active',
        ]);

        // Create a center with an active Plus subscription
        $center = User::factory()->center()->create(['status' => UserStatus::ACTIVE]);
        CenterSubscription::create([
            'center_id' => $center->id,
            'plan_tier' => CenterPlanTier::PLUS->value,
            'plan_name_ar' => 'باقة السنتر بلس',
            'plan_name_en' => 'Center Plus',
            'billing_period' => BillingPeriod::MONTHLY->value,
            'amount_egp' => 1500,
            'starts_at' => now()->subDays(2),
            'renews_at' => now()->addDays(28),
            'status' => 'active',
        ]);

        // Non-admin cannot download Excel export
        Sanctum::actingAs($student, ['access']);
        $this->get('/api/v1/admin/users/export-excel')->assertStatus(403);

        // Admin checks full student details -> gets BOTH subscriptions with start & expiry dates
        Sanctum::actingAs($admin, ['access']);
        $detailsRes = $this->getJson("/api/v1/admin/users/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.subscriptionsSummary.totalCount', 2)
            ->assertJsonPath('data.subscriptionsSummary.activeCount', 1)
            ->assertJsonPath('data.subscriptionsSummary.expiredCount', 1)
            ->assertJsonPath('data.subscriptionsSummary.totalSpentEgp', 1350)
            ->assertJsonPath('data.activeSubscription.planTier', 'pro');

        $subs = $detailsRes->json('data.subscriptions');
        $this->assertCount(2, $subs);
        $this->assertNotEmpty($subs[0]['startsDate']);
        $this->assertNotEmpty($subs[0]['endsDate']);
        $this->assertGreaterThan(300, $subs[0]['daysRemaining']);
        $this->assertSame('expired', $subs[1]['status']);

        // Teacher & Center details return their subscriptions too
        $this->getJson("/api/v1/admin/users/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('data.subscriptions.0.planNameAr', 'باقة المعلم برو');

        $this->getJson("/api/v1/admin/users/{$center->id}")
            ->assertOk()
            ->assertJsonPath('data.subscriptions.0.planNameAr', 'باقة السنتر بلس');

        // Users list includes currentSubscription cleanly
        $listRes = $this->getJson('/api/v1/admin/users?role=student')->assertOk();
        $this->assertSame('pro', $listRes->json('data.0.currentSubscription.planTier'));
        $this->assertSame(2, $listRes->json('data.0.subscriptionsCount'));

        // Export Excel (.xlsx) returns a genuine multi-sheet RTL workbook with formula neutralization
        $excelRes = $this->get('/api/v1/admin/users/export-excel')->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $excelRes->headers->get('Content-Type')
        );

        $binary = (string) $excelRes->getContent();
        $this->assertStringStartsWith("PK\x03\x04", $binary);

        $tmp = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        $sheet1 = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $sheet2 = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('rightToLeft="1"', $sheet1);
        $this->assertStringContainsString('&apos;=HYPERLINK(', $sheet1); // Formula neutralized & XML-escaped!
        $this->assertStringContainsString('باقة الطالب برو', $sheet1);
        $this->assertStringContainsString('باقة الطالب بلس', $sheet2);
        $this->assertStringContainsString('باقة المعلم برو', $sheet2);
        $this->assertStringContainsString('باقة السنتر بلس', $sheet2);
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.users.exported_excel')->exists());
    }
}
