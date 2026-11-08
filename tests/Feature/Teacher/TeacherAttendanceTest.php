<?php

namespace Tests\Feature\Teacher;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\SecurityAuditLog;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.attend@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'أحمد محمد فتحي', string $email = 'student.fathy@motafawweq.com'): User
    {
        return User::factory()->create([
            'name'              => $name,
            'email'             => $email,
            'phone'             => '012' . random_int(10000000, 99999999),
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'student_code'      => 'STD-' . random_int(100000, 999999),
            'email_verified_at' => now(),
        ]);
    }

    protected function createGroup(User $teacher): StudyGroup
    {
        return StudyGroup::create([
            'teacher_id'   => $teacher->id,
            'name_ar'      => 'فيزياء الثانوية العامة — سنتر الرواد',
            'subject_ar'   => 'الفيزياء',
            'grade_ar'     => 'الصف الثالث الثانوي',
            'center_name'  => 'سنتر الرواد — مدينة نصر',
            'max_students' => 50,
            'price_egp'    => 500,
            'join_code'    => 'PHY-ROWAAD-26',
        ]);
    }

    protected function enrollStudent(StudyGroup $group, User $student, string $barcode = 'STD-001-0001-BC1', string $passcode = '4821'): GroupEnrollment
    {
        return GroupEnrollment::create([
            'study_group_id'   => $group->id,
            'student_id'       => $student->id,
            'status'           => EnrollmentStatus::ENROLLED->value,
            'join_method'      => JoinMethod::QR_SCAN->value,
            'student_barcode'  => $barcode,
            'student_passcode' => $passcode,
            'attendance_rate'  => 100,
            'attended_sessions' => 0,
            'total_sessions'   => 0,
            'enrolled_at'      => now(),
        ]);
    }

    public function test_teacher_can_scan_student_attendance_by_barcode_and_idempotent_duplicate_scan(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent('عبدالرحمن حسن', 'abdo@motafawweq.com');
        $enrollment = $this->enrollStudent($group, $student, 'STD-BC-12345', '7788');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // First scan
        $response1 = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/scan", [
            'code'   => 'STD-BC-12345',
            'method' => AttendanceRecordMethod::BARCODE_INPUT->value,
        ]);

        $response1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.alreadyRecorded', false)
            ->assertJsonPath('data.record.status', AttendanceStatus::PRESENT->value)
            ->assertJsonPath('data.session.presentCount', 1)
            ->assertJsonPath('data.session.attendanceRate', fn ($rate) => (float) $rate === 100.0);

        $this->assertDatabaseHas('group_attendance_records', [
            'study_group_id'      => $group->id,
            'group_enrollment_id' => $enrollment->id,
            'student_id'          => $student->id,
            'status'              => AttendanceStatus::PRESENT->value,
        ]);

        $this->assertEquals(1, $enrollment->fresh()->attended_sessions);
        $this->assertEquals(1, $enrollment->fresh()->streak_days);

        // Second scan (idempotent duplicate within same session)
        $response2 = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/scan", [
            'code'   => 'STD-BC-12345',
            'method' => AttendanceRecordMethod::BARCODE_INPUT->value,
        ]);

        $response2->assertOk()
            ->assertJsonPath('data.alreadyRecorded', true)
            ->assertJsonPath('data.record.status', AttendanceStatus::PRESENT->value);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.attendance.scanned',
        ]);
    }

    public function test_teacher_can_scan_attendance_by_passcode(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent();
        $this->enrollStudent($group, $student, 'STD-BC-999', '5544');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/scan", [
            'code'   => '5544',
            'method' => AttendanceRecordMethod::PASSCODE->value,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.alreadyRecorded', false)
            ->assertJsonPath('data.record.status', AttendanceStatus::PRESENT->value);
    }

    public function test_teacher_can_scan_attendance_by_hmac_signed_qr_token(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent();
        $enrollment = $this->enrollStudent($group, $student);

        $timestamp = time();
        $hmac = hash_hmac('sha256', "{$group->id}:{$enrollment->uuid}:{$timestamp}", (string) config('app.key'));
        $validQrToken = "MOTAFAWWEQ:ATTEND:{$group->id}:{$enrollment->uuid}:{$timestamp}:{$hmac}";

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/scan", [
            'code'   => $validQrToken,
            'method' => AttendanceRecordMethod::QR_SCAN->value,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.record.status', AttendanceStatus::PRESENT->value);

        // Spoofed/tampered HMAC token fails
        $fakeQrToken = "MOTAFAWWEQ:ATTEND:{$group->id}:{$enrollment->uuid}:{$timestamp}:invalid_hmac_signature";
        $fakeResponse = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/scan", [
            'code' => $fakeQrToken,
        ]);

        $fakeResponse->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_teacher_can_manually_toggle_attendance_status(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent();
        $enrollment = $this->enrollStudent($group, $student);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Toggle to present
        $res1 = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/toggle", [
            'enrollment_id' => $enrollment->uuid,
        ]);

        $res1->assertOk()
            ->assertJsonPath('data.status', AttendanceStatus::PRESENT->value);

        $this->assertEquals(1, $enrollment->fresh()->attended_sessions);

        // Toggle to absent
        $res2 = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/attendance/toggle", [
            'enrollment_id' => $enrollment->uuid,
        ]);

        $res2->assertOk()
            ->assertJsonPath('data.status', AttendanceStatus::ABSENT->value);

        $this->assertEquals(0, $enrollment->fresh()->attended_sessions);
    }

    public function test_teacher_can_list_sessions_and_view_detailed_roster(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent('سارة منصور', 'sara@motafawweq.com');
        $enrollment = $this->enrollStudent($group, $student);

        $session = GroupAttendanceSession::create([
            'study_group_id' => $group->id,
            'teacher_id'     => $teacher->id,
            'session_date'   => now()->toDateString(),
            'title_ar'       => 'حصة المراجعة النهائية',
            'status'         => 'open',
            'total_enrolled' => 1,
            'present_count'  => 1,
            'attendance_rate' => 100.0,
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $session->id,
            'study_group_id'        => $group->id,
            'group_enrollment_id'   => $enrollment->id,
            'student_id'            => $student->id,
            'status'                => AttendanceStatus::PRESENT->value,
            'recorded_method'       => AttendanceRecordMethod::QR_SCAN->value,
            'scanned_at'            => now(),
            'recorded_by'           => $teacher->id,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // List sessions
        $sessionsRes = $this->getJson("/api/v1/teacher/groups/{$group->uuid}/attendance/sessions");
        $sessionsRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titleAr', 'حصة المراجعة النهائية');

        // Detailed session roster
        $detailRes = $this->getJson("/api/v1/teacher/groups/{$group->uuid}/attendance/sessions/{$session->uuid}");
        $detailRes->assertOk()
            ->assertJsonPath('data.session.titleAr', 'حصة المراجعة النهائية')
            ->assertJsonCount(1, 'data.roster')
            ->assertJsonPath('data.roster.0.nameAr', 'سارة منصور')
            ->assertJsonPath('data.roster.0.status', AttendanceStatus::PRESENT->value);
    }

    public function test_cross_teacher_idor_on_attendance_is_strictly_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.attend@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b.attend@motafawweq.com');

        $groupA = $this->createGroup($teacherA);
        $student = $this->createStudent();
        $enrollmentA = $this->enrollStudent($groupA, $student, 'BARCODE-SECRET-A');

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Teacher B tries to scan Teacher A's group
        $this->postJson("/api/v1/teacher/groups/{$groupA->uuid}/attendance/scan", [
            'code' => 'BARCODE-SECRET-A',
        ])->assertForbidden();

        // Teacher B tries to toggle Teacher A's student attendance
        $this->postJson("/api/v1/teacher/groups/{$groupA->uuid}/attendance/toggle", [
            'enrollment_id' => $enrollmentA->uuid,
        ])->assertForbidden();

        // Teacher B tries to list Teacher A's attendance sessions
        $this->getJson("/api/v1/teacher/groups/{$groupA->uuid}/attendance/sessions")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}
