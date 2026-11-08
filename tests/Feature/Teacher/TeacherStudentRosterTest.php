<?php

namespace Tests\Feature\Teacher;

use App\Enums\AcademicStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\JoinMethod;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GroupEnrollment;
use App\Models\SecurityAuditLog;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherStudentRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.roster@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'عمر طارق القاضي', string $email = 'student.omar@motafawweq.com'): User
    {
        return User::factory()->create([
            'name'              => $name,
            'email'             => $email,
            'phone'             => '010' . random_int(10000000, 99999999),
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'student_code'      => 'STD-' . random_int(100000, 999999),
            'email_verified_at' => now(),
        ]);
    }

    protected function createGroup(User $teacher, int $maxStudents = 50): StudyGroup
    {
        return StudyGroup::create([
            'teacher_id'   => $teacher->id,
            'name_ar'      => 'أحياء 3 ثانوي — الدقي',
            'subject_ar'   => 'الأحياء',
            'grade_ar'     => 'الصف الثالث الثانوي',
            'center_name'  => 'سنتر الرواد',
            'max_students' => $maxStudents,
            'price_egp'    => 450,
            'join_code'    => 'BIO-DK-99',
        ]);
    }

    public function test_teacher_can_fetch_roster_with_enrolled_and_pending_students(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student1 = $this->createStudent('سارة خالد منصور', 'sarah@motafawweq.com');
        $student2 = $this->createStudent('كريم مصطفى بدوي', 'kareem@motafawweq.com');

        // Enrolled student
        GroupEnrollment::create([
            'study_group_id'   => $group->id,
            'student_id'       => $student1->id,
            'status'           => EnrollmentStatus::ENROLLED->value,
            'join_method'      => JoinMethod::QR_SCAN->value,
            'student_barcode'  => 'STD-001-0001-A1B2',
            'student_passcode' => '4821',
            'attendance_rate'  => 95,
            'enrolled_at'      => now(),
        ]);

        // Pending student
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id'     => $student2->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::JOIN_CODE->value,
            'requested_at'   => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson("/api/v1/teacher/groups/{$group->uuid}/roster");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.enrolledCount', 1)
            ->assertJsonPath('data.stats.pendingCount', 1)
            ->assertJsonCount(1, 'data.enrolled')
            ->assertJsonPath('data.enrolled.0.nameAr', 'سارة خالد منصور')
            ->assertJsonPath('data.enrolled.0.studentBarcode', 'STD-001-0001-A1B2')
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.nameAr', 'كريم مصطفى بدوي');
    }

    public function test_teacher_can_search_registered_students_to_invite(): void
    {
        $teacher = $this->createTeacher();
        $this->createStudent('مريم عادل شنودة', 'mariam@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/students/search?q=مريم');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مريم عادل شنودة');
    }

    public function test_teacher_can_invite_student_directly_to_group(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent('يوسف أحمد الشناوي', 'youssef@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/invite", [
            'student_id'     => (string) $student->id,
            'parent_name_ar' => 'أحمد الشناوي',
            'parent_phone'   => '01011122233',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'يوسف أحمد الشناوي')
            ->assertJsonPath('data.parentNameAr', 'أحمد الشناوي');

        $this->assertDatabaseHas('group_enrollments', [
            'study_group_id' => $group->id,
            'student_id'     => $student->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::TEACHER_INVITE->value,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.roster.student_invited',
        ]);
    }

    public function test_teacher_can_accept_pending_student_generating_barcode_and_passcode(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent('سلمى محمود عزت', 'salma@motafawweq.com');

        $enrollment = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id'     => $student->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::QR_SCAN->value,
            'requested_at'   => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/enrollments/{$enrollment->uuid}/accept");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'سلمى محمود عزت')
            ->assertJsonPath('data.attendanceRate', 100);

        $barcode = $response->json('data.studentBarcode');
        $passcode = $response->json('data.studentPasscode');

        $this->assertNotNull($barcode);
        $this->assertNotNull($passcode);

        $this->assertDatabaseHas('group_enrollments', [
            'id'               => $enrollment->id,
            'status'           => EnrollmentStatus::ENROLLED->value,
            'student_barcode'  => $barcode,
            'student_passcode' => $passcode,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.roster.student_accepted',
        ]);
    }

    public function test_teacher_can_reject_pending_student(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent();

        $enrollment = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id'     => $student->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::JOIN_CODE->value,
            'requested_at'   => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/enrollments/{$enrollment->uuid}/reject");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('group_enrollments', [
            'id'     => $enrollment->id,
            'status' => EnrollmentStatus::REJECTED->value,
        ]);
    }

    public function test_teacher_can_remove_enrolled_student(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent();

        $enrollment = GroupEnrollment::create([
            'study_group_id'   => $group->id,
            'student_id'       => $student->id,
            'status'           => EnrollmentStatus::ENROLLED->value,
            'student_barcode'  => 'STD-001-9999-ABCD',
            'student_passcode' => '1234',
            'enrolled_at'      => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->deleteJson("/api/v1/teacher/groups/{$group->uuid}/enrollments/{$enrollment->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('group_enrollments', ['id' => $enrollment->id]);
    }

    public function test_student_can_request_join_with_join_code(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher);
        $student = $this->createStudent('نورهان هاني الجوهري', 'nourhan@motafawweq.com');

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $response = $this->postJson('/api/v1/groups/join', [
            'join_code'      => $group->join_code,
            'parent_name_ar' => 'هاني الجوهري',
            'parent_phone'   => '01005566778',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'نورهان هاني الجوهري');

        $this->assertDatabaseHas('group_enrollments', [
            'study_group_id' => $group->id,
            'student_id'     => $student->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::JOIN_CODE->value,
        ]);
    }

    public function test_group_capacity_exceeded_blocks_new_enrollments(): void
    {
        $teacher = $this->createTeacher();
        $group = $this->createGroup($teacher, maxStudents: 1);
        $student1 = $this->createStudent('طالب أول', 'std1@motafawweq.com');
        $student2 = $this->createStudent('طالب ثاني', 'std2@motafawweq.com');

        // Fill capacity with 1 student
        GroupEnrollment::create([
            'study_group_id'   => $group->id,
            'student_id'       => $student1->id,
            'status'           => EnrollmentStatus::ENROLLED->value,
            'student_barcode'  => 'STD-001-0001-CAP1',
            'student_passcode' => '1111',
            'enrolled_at'      => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Attempting to invite another student when group capacity is 1
        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/invite", [
            'student_id' => (string) $student2->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['group']);
    }

    public function test_cross_teacher_idor_on_roster_operations_is_strictly_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.roster@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b.roster@motafawweq.com');

        $groupA = $this->createGroup($teacherA);
        $student = $this->createStudent();

        $enrollmentA = GroupEnrollment::create([
            'study_group_id' => $groupA->id,
            'student_id'     => $student->id,
            'status'         => EnrollmentStatus::PENDING->value,
            'join_method'    => JoinMethod::QR_SCAN->value,
            'requested_at'   => now(),
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Teacher B tries to view Teacher A's roster
        $this->getJson("/api/v1/teacher/groups/{$groupA->uuid}/roster")
            ->assertForbidden();

        // Teacher B tries to accept Teacher A's pending student
        $this->postJson("/api/v1/teacher/groups/{$groupA->uuid}/enrollments/{$enrollmentA->uuid}/accept")
            ->assertForbidden();

        // Teacher B tries to reject Teacher A's student
        $this->postJson("/api/v1/teacher/groups/{$groupA->uuid}/enrollments/{$enrollmentA->uuid}/reject")
            ->assertForbidden();

        // Teacher B tries to delete Teacher A's student
        $this->deleteJson("/api/v1/teacher/groups/{$groupA->uuid}/enrollments/{$enrollmentA->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}

