<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentWeeklyScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(): User
    {
        return User::factory()->create([
            'name' => 'أ. محمود الفيزياء',
            'email' => 'sched.teacher.'.random_int(1000, 9999).'@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'سارة أحمد'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'sched.student.'.random_int(1000, 9999).'@motafawweq.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(100000, 999999),
        ]);
    }

    protected function createGroupWithSlot(
        User $teacher,
        string $code,
        string $day,
        string $startTime,
        string $endTime,
        string $nameAr = 'مجموعة الفيزياء'
    ): StudyGroup {
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء للثانوية العامة',
            'title_en' => 'Physics',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => $nameAr,
            'name_en' => 'Physics Cohort',
            'subject_ar' => 'الفيزياء',
            'subject_en' => 'Physics',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة B2',
            'schedule_ar' => 'الأحد 4:00 عصراً',
            'price_egp' => 400,
            'max_students' => 40,
            'join_code' => $code,
            'status' => 'active',
        ]);

        GroupScheduleSlot::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'hall_name' => 'قاعة B2',
        ]);

        return $group;
    }

    public function test_weekly_schedule_includes_only_confirmed_enrolled_groups_and_computes_summary(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $enrolledGroup = $this->createGroupWithSlot($teacher, 'PHY-1001', DayOfWeek::SUNDAY->value, '16:00', '18:00', 'مجموعة مؤكدة');
        $pendingGroup = $this->createGroupWithSlot($teacher, 'PHY-2002', DayOfWeek::MONDAY->value, '14:00', '16:00', 'مجموعة قيد الانتظار');

        GroupEnrollment::create([
            'study_group_id' => $enrolledGroup->id,
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 92,
            'enrolled_at' => now(),
        ]);

        GroupEnrollment::create([
            'study_group_id' => $pendingGroup->id,
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::PENDING,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Create two personal study sessions & toggle one completed
        $first = $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'saturday',
            'subject' => 'الفيزياء',
            'title' => 'حل شيت الفصل الأول',
            'startTime' => '19:00',
            'endTime' => '20:30',
            'color' => '#7C3AED',
            'notes' => 'التركيز على كيرشوف',
        ])->assertCreated();

        $firstUuid = $first->json('data.id');
        $this->assertEquals(90, $first->json('data.durationMinutes'));
        $this->assertEquals('#7C3AED', $first->json('data.color'));

        $this->patchJson("/api/v1/student/study-sessions/{$firstUuid}/toggle")
            ->assertOk()
            ->assertJsonPath('data.isCompleted', true);

        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'sunday',
            'subject' => 'الأحياء',
            'title' => 'مراجعة الدعامة والحركة',
            'startTime' => '19:00',
            'endTime' => '20:00',
            'color' => '#059669',
        ])->assertCreated();

        $res = $this->getJson('/api/v1/student/schedule')->assertOk();

        // Only confirmed enrolled group appears in classSessions (pending excluded)
        $res->assertJsonCount(1, 'data.classSessions')
            ->assertJsonPath('data.classSessions.0.groupName', 'مجموعة مؤكدة')
            ->assertJsonPath('data.classSessions.0.joinCode', 'PHY-1001')
            ->assertJsonPath('data.classSessions.0.attendanceRate', 92)
            ->assertJsonPath('data.summary.totalCenterSessions', 1)
            ->assertJsonPath('data.summary.totalStudySessions', 2)
            ->assertJsonPath('data.summary.completedStudySessions', 1)
            ->assertJsonPath('data.summary.totalStudyMinutes', 150)
            ->assertJsonPath('data.summary.completionRate', 50);
    }

    public function test_study_session_validation_overlap_prevention_update_delete_and_idor(): void
    {
        $teacher = $this->createTeacher();
        $studentA = $this->createStudent('طالب أ');
        $studentB = $this->createStudent('طالب ب');

        $group = $this->createGroupWithSlot($teacher, 'BIO-3030', DayOfWeek::TUESDAY->value, '16:00', '18:00');
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'student_id' => $studentA->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // Invalid time format (25:99) & invalid color must be rejected
        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'tuesday',
            'subject' => 'الأحياء',
            'title' => 'جلسة غير صالحة',
            'startTime' => '25:99',
            'endTime' => '26:00',
            'color' => 'red;background:url(x)',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time', 'color']);

        // Overlap with enrolled center class (16:00 - 18:00) must be blocked
        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'tuesday',
            'subject' => 'الأحياء',
            'title' => 'جلسة متعارضة مع السنتر',
            'startTime' => '17:00',
            'endTime' => '19:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // Valid session after center class (18:00 - 20:00) succeeds (touching boundary is allowed)
        $created = $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'tuesday',
            'subject' => 'الأحياء',
            'title' => 'مراجعة مسائية',
            'startTime' => '18:00',
            'endTime' => '20:00',
            'color' => '#1588C7',
        ])->assertCreated();

        $uuid = $created->json('data.id');

        // Overlap with own study session must be blocked
        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'tuesday',
            'subject' => 'الكيمياء',
            'title' => 'متعارضة مع مذاكرة أخرى',
            'startTime' => '19:30',
            'endTime' => '21:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // Updating the same session in-place (expanding to 18:00 - 20:30) does NOT self-conflict
        $this->putJson("/api/v1/student/study-sessions/{$uuid}", [
            'day' => 'tuesday',
            'subject' => 'الأحياء',
            'title' => 'مراجعة مسائية مطورة',
            'startTime' => '18:00',
            'endTime' => '20:30',
            'color' => '#D97706',
            'notes' => 'تم التحديث',
        ])->assertOk()
            ->assertJsonPath('data.title', 'مراجعة مسائية مطورة')
            ->assertJsonPath('data.durationMinutes', 150)
            ->assertJsonPath('data.color', '#D97706');

        // Cross-student IDOR protection on update, toggle, and delete
        Sanctum::actingAs($studentB, [TokenType::ACCESS->value]);
        $this->putJson("/api/v1/student/study-sessions/{$uuid}", [
            'day' => 'tuesday',
            'subject' => 'اختراق',
            'title' => 'تعديل غير مصرح',
            'startTime' => '18:00',
            'endTime' => '19:00',
        ])->assertForbidden();
        $this->patchJson("/api/v1/student/study-sessions/{$uuid}/toggle")->assertForbidden();
        $this->deleteJson("/api/v1/student/study-sessions/{$uuid}")->assertForbidden();

        // Owner deletes cleanly
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/study-sessions/{$uuid}")->assertOk();
    }

    public function test_schedule_notes_full_crud_including_day_move_validation_and_idor(): void
    {
        $studentA = $this->createStudent('أحمد');
        $studentB = $this->createStudent('يوسف');

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // Invalid time rejected
        $this->postJson('/api/v1/student/schedule/notes', [
            'day_of_week' => 'saturday',
            'text' => 'ملحوظة بوقت غير صالح',
            'time' => '99:99',
            'priority' => 'high',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['time']);

        // Create note with optional empty time
        $created = $this->postJson('/api/v1/student/schedule/notes', [
            'day_of_week' => 'saturday',
            'text' => 'طباعة ملزمة الكيمياء العضوية',
            'time' => '',
            'priority' => 'high',
        ])->assertCreated();

        $noteUuid = $created->json('data.id');
        $this->assertNull($created->json('data.time'));
        $this->assertEquals('high', $created->json('data.priority'));

        // Update note text, priority, time, and move it from saturday to monday
        $this->putJson("/api/v1/student/schedule/notes/{$noteUuid}", [
            'day_of_week' => 'monday',
            'text' => 'مراجعة 20 سؤال عضوية قبل الحصة',
            'time' => '17:30',
            'priority' => 'medium',
        ])->assertOk()
            ->assertJsonPath('data.day', 'monday')
            ->assertJsonPath('data.previousDay', 'saturday')
            ->assertJsonPath('data.text', 'مراجعة 20 سؤال عضوية قبل الحصة')
            ->assertJsonPath('data.time', '17:30')
            ->assertJsonPath('data.priority', 'medium');

        // Toggle done returns full payload
        $this->patchJson("/api/v1/student/schedule/notes/{$noteUuid}/toggle")
            ->assertOk()
            ->assertJsonPath('data.isDone', true)
            ->assertJsonPath('data.text', 'مراجعة 20 سؤال عضوية قبل الحصة');

        // Verify schedule reflects note under monday, not saturday
        $this->getJson('/api/v1/student/schedule')
            ->assertOk()
            ->assertJsonCount(0, 'data.scheduleNotes.saturday')
            ->assertJsonCount(1, 'data.scheduleNotes.monday')
            ->assertJsonPath('data.summary.totalNotes', 1)
            ->assertJsonPath('data.summary.completedNotes', 1);

        // Cross-student IDOR blocked on update, toggle, and delete
        Sanctum::actingAs($studentB, [TokenType::ACCESS->value]);
        $this->putJson("/api/v1/student/schedule/notes/{$noteUuid}", [
            'day_of_week' => 'monday',
            'text' => 'اختراق',
            'priority' => 'low',
        ])->assertForbidden();
        $this->patchJson("/api/v1/student/schedule/notes/{$noteUuid}/toggle")->assertForbidden();
        $this->deleteJson("/api/v1/student/schedule/notes/{$noteUuid}")->assertForbidden();

        // Owner deletes cleanly
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/schedule/notes/{$noteUuid}")->assertOk();
    }
}
