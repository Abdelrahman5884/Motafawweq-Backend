<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\GroupScheduleSlot;
use App\Models\StudentProfile;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentGroupsAndCardTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'grp.teacher@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'د. سلمى الأحياء',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $email = 'grp.student@motafawweq.com', string $name = 'عمر طارق القاضي', ?string $studentCode = null): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => $studentCode ?? 'STD-'.random_int(100000, 999999),
        ]);
    }

    protected function createGroup(User $teacher, string $code, string $nameAr = 'مجموعة الدقي المتميزة', int $maxStudents = 30): StudyGroup
    {
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الأحياء للثانوية العامة',
            'title_en' => 'High School Biology',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => $nameAr,
            'name_en' => 'Dokki Elite Group',
            'subject_ar' => 'الأحياء',
            'subject_en' => 'Biology',
            'grade_ar' => 'الصف الثالث الثانوي',
            'grade_en' => '3rd Secondary',
            'center_name' => 'سنتر الرواد — الدقي',
            'hall_name' => 'قاعة A1',
            'schedule_ar' => 'الأحد والأربعاء 4:00 عصراً',
            'schedule_en' => 'Sun & Wed 4:00 PM',
            'price_egp' => 450,
            'max_students' => $maxStudents,
            'join_code' => $code,
            'status' => 'active',
        ]);

        GroupScheduleSlot::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::SUNDAY->value,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'hall_name' => 'قاعة A1',
        ]);

        return $group;
    }

    public function test_student_can_join_by_code_or_qr_url_see_full_group_details_and_leave(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $groupA = $this->createGroup($teacher, 'BIO-DK-2026', 'مجموعة الأحياء — الدقي');
        $groupB = $this->createGroup($teacher, 'PHY-NS-2026', 'مجموعة الفيزياء — مدينة نصر');

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Join Group A using lowercase code with extra spaces
        $this->postJson('/api/v1/student/groups/join', [
            'join_code' => '  bio-dk-2026  ',
        ])->assertCreated();

        // 2. Duplicate pending request returns clean 422
        $this->postJson('/api/v1/student/groups/join', [
            'join_code' => 'BIO-DK-2026',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['join_code']);

        // 3. Join Group B by pasting a full QR join URL!
        $this->postJson('/api/v1/student/groups/join', [
            'join_code' => 'https://motafawweq.me/join-group/phy-ns-2026',
        ])->assertCreated();

        // 4. Listing student's groups MUST return accurate joinCode, priceEgp, teacherName, scheduleSlots with dayAr
        $listRes = $this->getJson('/api/v1/student/groups')->assertOk();
        $groupsData = collect($listRes->json('data'));
        $this->assertCount(2, $groupsData);

        $first = $groupsData->firstWhere('groupId', $groupA->uuid);
        $this->assertNotNull($first);
        $this->assertEquals('BIO-DK-2026', $first['joinCode']);
        $this->assertEquals(450, $first['priceEgp']);
        $this->assertEquals('د. سلمى الأحياء', $first['teacherName']);
        $this->assertEquals('pending', $first['enrollmentStatus']);
        $this->assertEquals('الأحد', $first['scheduleSlots'][0]['dayAr']);
        $this->assertNull($first['studentBarcode']); // masked until teacher accepts

        // 5. Cancel pending request (leave Group B)
        $this->deleteJson("/api/v1/student/groups/{$groupB->uuid}/leave")->assertOk();

        $afterLeave = collect($this->getJson('/api/v1/student/groups')->assertOk()->json('data'));
        $this->assertCount(1, $afterLeave);

        // 6. Re-joining Group B after leaving works cleanly
        $this->postJson('/api/v1/student/groups/join', [
            'join_code' => 'PHY-NS-2026',
        ])->assertCreated();
    }

    public function test_student_center_pass_qr_and_barcode_scan_successfully_across_all_enrolled_groups(): void
    {
        $teacher = $this->createTeacher('pass.teacher@motafawweq.com');
        $student = $this->createStudent('pass.student@motafawweq.com', 'أحمد علاء الدين', 'OMAR-2026');

        StudentProfile::create([
            'student_id' => $student->id,
            'grade_ar' => 'الصف الثالث الثانوي',
            'track_ar' => 'علمي علوم',
            'school_name' => 'مدرسة المتفوقين',
        ]);

        $group1 = $this->createGroup($teacher, 'GRP-1001', 'مجموعة الأحياء 1');
        $group2 = $this->createGroup($teacher, 'GRP-2002', 'مجموعة الأحياء 2');

        // Student requests both groups
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $req1 = $this->postJson('/api/v1/student/groups/join', ['join_code' => 'GRP-1001'])->assertCreated();
        $req2 = $this->postJson('/api/v1/student/groups/join', ['join_code' => 'GRP-2002'])->assertCreated();

        // Teacher accepts student into both groups
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/teacher/groups/{$group1->uuid}/enrollments/{$req1->json('data.id')}/accept")->assertOk();
        $this->postJson("/api/v1/teacher/groups/{$group2->uuid}/enrollments/{$req2->json('data.id')}/accept")->assertOk();

        // Student fetches digital & printable center card data
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $passRes = $this->getJson('/api/v1/student/attendance')->assertOk();
        $pass = $passRes->json('data');

        $this->assertEquals('أحمد علاء الدين', $pass['studentName']);
        $this->assertEquals('OMAR-2026', $pass['studentCode']);
        $this->assertEquals('علمي علوم', $pass['trackAr']);
        $this->assertEquals('MOTAFAWWEQ:STUDENT:OMAR-2026', $pass['permanentQrPayload']);
        $this->assertStringStartsWith("MOTAFAWWEQ:ATT:{$student->id}:", $pass['rotatingQrPayload']);
        $this->assertCount(2, $pass['enrolledGroups']);

        // Teacher scans student's LIVE rotating QR token in Group 1 -> MUST SUCCEED!
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/teacher/groups/{$group1->uuid}/attendance/scan", [
            'code' => $pass['rotatingQrPayload'],
            'method' => 'qr_scan',
        ])->assertOk();

        // Teacher scans student's PERMANENT PRINTED QR payload in Group 2 -> MUST SUCCEED!
        $this->postJson("/api/v1/teacher/groups/{$group2->uuid}/attendance/scan", [
            'code' => $pass['permanentQrPayload'],
            'method' => 'qr_scan',
        ])->assertOk();

        // Student attendance overview now reflects 2 present attendance records
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/attendance')
            ->assertOk()
            ->assertJsonPath('data.attendanceSummary.presentCount', 2)
            ->assertJsonPath('data.attendanceSummary.overallRate', 100);
    }

    public function test_schedule_notes_validate_day_and_enforce_student_ownership(): void
    {
        $student = $this->createStudent('notes.std@motafawweq.com');
        $other = $this->createStudent('notes.other@motafawweq.com', 'طالب آخر', 'OTH-9999');

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Invalid day rejected cleanly with 422
        $this->postJson('/api/v1/student/schedule/notes', [
            'day_of_week' => 'funday',
            'text' => 'مراجعة الفصل الأول',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['day_of_week']);

        // Valid note created, toggled, and protected against IDOR
        $created = $this->postJson('/api/v1/student/schedule/notes', [
            'day_of_week' => 'sunday',
            'text' => 'طباعة ملزمة الأحياء',
            'time' => '15:00',
            'priority' => 'high',
        ])->assertCreated();

        $noteId = $created->json('data.id');

        $this->patchJson("/api/v1/student/schedule/notes/{$noteId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.isDone', true);

        Sanctum::actingAs($other, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/schedule/notes/{$noteId}")->assertForbidden();

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/schedule/notes/{$noteId}")->assertOk();
    }
}
