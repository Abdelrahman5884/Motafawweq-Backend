<?php

namespace Tests\Feature\Admin;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use App\Models\CenterProfile;
use App\Models\Course;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\SecurityAuditLog;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

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
}
