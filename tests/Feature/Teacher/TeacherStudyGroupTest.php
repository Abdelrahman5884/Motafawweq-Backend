<?php

namespace Tests\Feature\Teacher;

use App\Enums\DayOfWeek;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GroupScheduleSlot;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\TeacherScheduleNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherStudyGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.groups@motafawweq.com'): User
    {
        $teacher = User::factory()->create([
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id' => $teacher->id,
            'name_ar' => $teacher->name,
        ])->forceFill(['plan_tier' => TeacherPlanTier::PLUS])->save();

        return $teacher;
    }

    public function test_teacher_can_create_study_group_with_schedule_slots_and_unique_join_code(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'nameAr' => 'أحياء 3 ثانوي 2026 — مجموعة النخبة',
            'nameEn' => 'Thanawya Amma Biology 2026 — Elite Cohort',
            'subjectAr' => 'الأحياء (الثانوية العامة)',
            'gradeAr' => 'الصف الثالث الثانوي',
            'centerName' => 'سنتر الرواد التعليمي — فرع الدقي',
            'hallName' => 'قاعة 1 (المحاضرات الكبرى)',
            'priceEgp' => 450,
            'maxStudents' => 60,
            'joinCode' => 'BIO-DK-2026',
            'scheduleSlots' => [
                [
                    'day' => DayOfWeek::SUNDAY->value,
                    'startTime' => '16:00',
                    'endTime' => '18:00',
                    'hall' => 'قاعة 1 (المحاضرات الكبرى)',
                ],
                [
                    'day' => DayOfWeek::WEDNESDAY->value,
                    'startTime' => '16:00',
                    'endTime' => '18:00',
                    'hall' => 'قاعة 1 (المحاضرات الكبرى)',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/teacher/groups', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'أحياء 3 ثانوي 2026 — مجموعة النخبة')
            ->assertJsonPath('data.centerName', 'سنتر الرواد التعليمي — فرع الدقي')
            ->assertJsonPath('data.joinCode', 'BIO-DK-2026')
            ->assertJsonPath('data.qrCodeData', 'MOTAFAWWEQ:JOIN:BIO-DK-2026')
            ->assertJsonPath('data.priceEgp', 450)
            ->assertJsonPath('data.maxStudents', 60)
            ->assertJsonCount(2, 'data.scheduleSlots');

        $this->assertDatabaseHas('study_groups', [
            'teacher_id' => $teacher->id,
            'join_code' => 'BIO-DK-2026',
            'center_name' => 'سنتر الرواد التعليمي — فرع الدقي',
        ]);

        $this->assertDatabaseHas('group_schedule_slots', [
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::SUNDAY->value,
            'start_time' => '16:00',
            'end_time' => '18:00',
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event' => 'teacher.group.created',
        ]);
    }

    public function test_teacher_can_list_groups_and_filter_by_center(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $group1 = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة الدقي',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'join_code' => 'PHY-DK-01',
        ]);

        $group2 = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة المعادي',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر المعادي',
            'join_code' => 'PHY-MD-02',
        ]);

        $allResponse = $this->getJson('/api/v1/teacher/groups');
        $allResponse->assertOk()
            ->assertJsonCount(2, 'data');

        $filterResponse = $this->getJson('/api/v1/teacher/groups?center_name=سنتر+الدقي');
        $filterResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $group1->uuid);
    }

    public function test_teacher_can_update_group_and_reorder_schedule(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة الكيمياء',
            'subject_ar' => 'كيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النور',
            'price_egp' => 400,
            'join_code' => 'CHM-NOOR-01',
        ]);

        $response = $this->putJson("/api/v1/teacher/groups/{$group->uuid}", [
            'nameAr' => 'مجموعة الكيمياء المتقدمة (معدلة)',
            'priceEgp' => 480,
            'scheduleSlots' => [
                [
                    'day' => DayOfWeek::MONDAY->value,
                    'startTime' => '17:00',
                    'endTime' => '19:00',
                    'hall' => 'قاعة فاراداي',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.nameAr', 'مجموعة الكيمياء المتقدمة (معدلة)')
            ->assertJsonPath('data.priceEgp', 480)
            ->assertJsonCount(1, 'data.scheduleSlots')
            ->assertJsonPath('data.scheduleSlots.0.day', DayOfWeek::MONDAY->value);

        $this->assertDatabaseHas('study_groups', [
            'id' => $group->id,
            'name_ar' => 'مجموعة الكيمياء المتقدمة (معدلة)',
            'price_egp' => 480,
        ]);
    }

    public function test_teacher_can_regenerate_join_code_safely(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة تجريبية',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الرواد',
            'join_code' => 'OLD-LEAKED-CODE-1',
        ]);

        $response = $this->postJson("/api/v1/teacher/groups/{$group->uuid}/regenerate-code");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $newCode = $response->json('data.joinCode');
        $this->assertNotEquals('OLD-LEAKED-CODE-1', $newCode);

        $this->assertDatabaseHas('study_groups', [
            'id' => $group->id,
            'join_code' => $newCode,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event' => 'teacher.group.code_regenerated',
        ]);
    }

    public function test_teacher_can_fetch_aggregated_weekly_schedule_and_notes(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة الفيزياء 1',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر العباقرة',
            'join_code' => 'PHY-GEN-01',
        ]);

        GroupScheduleSlot::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::TUESDAY->value,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'hall_name' => 'مدرج 2',
        ]);

        // Add a note for Tuesday
        $this->postJson('/api/v1/teacher/schedule/notes', [
            'day' => DayOfWeek::TUESDAY->value,
            'text' => 'إحضار نماذج امتحانات البابل شيت وتوزيعها على الطلاب',
            'time' => '13:45',
            'priority' => 'high',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/teacher/schedule');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totalSessions', 1)
            ->assertJsonPath('data.activeGroupsCount', 1)
            ->assertJsonCount(7, 'data.days')
            ->assertJsonCount(1, 'data.days.tuesday.sessions')
            ->assertJsonPath('data.days.tuesday.sessions.0.groupName', 'مجموعة الفيزياء 1')
            ->assertJsonCount(1, 'data.days.tuesday.notes')
            ->assertJsonPath('data.days.tuesday.notes.0.text', 'إحضار نماذج امتحانات البابل شيت وتوزيعها على الطلاب')
            ->assertJsonPath('data.days.tuesday.notes.0.priority', 'high');
    }

    public function test_teacher_can_toggle_and_delete_daily_schedule_note(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $note = TeacherScheduleNote::create([
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::WEDNESDAY->value,
            'note_text' => 'مراجعة واجب الفصل الأول',
            'note_time' => '16:00',
            'priority' => 'normal',
            'is_done' => false,
        ]);

        // Toggle to true
        $toggleResponse = $this->patchJson("/api/v1/teacher/schedule/notes/{$note->uuid}/toggle");
        $toggleResponse->assertOk()
            ->assertJsonPath('data.isDone', true);

        $this->assertTrue((bool) $note->fresh()->is_done);

        // Delete note
        $deleteResponse = $this->deleteJson("/api/v1/teacher/schedule/notes/{$note->uuid}");
        $deleteResponse->assertOk();

        $this->assertDatabaseMissing('teacher_schedule_notes', ['id' => $note->id]);
    }

    public function test_cross_teacher_idor_on_groups_is_strictly_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.groups@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b.groups@motafawweq.com');

        $groupA = StudyGroup::create([
            'teacher_id' => $teacherA->id,
            'name_ar' => 'مجموعة المعلم أ السرية',
            'subject_ar' => 'أحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر أ',
            'join_code' => 'SECRET-GRP-A',
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Teacher B tries to view Teacher A's group
        $this->getJson("/api/v1/teacher/groups/{$groupA->uuid}")
            ->assertForbidden();

        // Teacher B tries to modify Teacher A's group
        $this->putJson("/api/v1/teacher/groups/{$groupA->uuid}", [
            'nameAr' => 'اختراق اسم المجموعة',
        ])->assertForbidden();

        // Teacher B tries to regenerate join code of Teacher A's group
        $this->postJson("/api/v1/teacher/groups/{$groupA->uuid}/regenerate-code")
            ->assertForbidden();

        // Teacher B tries to delete Teacher A's group
        $this->deleteJson("/api/v1/teacher/groups/{$groupA->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event' => 'teacher.idor_attempt_blocked',
            'level' => 'warning',
        ]);
    }

    public function test_cross_teacher_idor_on_schedule_notes_is_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.notes@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b.notes@motafawweq.com');

        $noteA = TeacherScheduleNote::create([
            'teacher_id' => $teacherA->id,
            'day_of_week' => DayOfWeek::THURSDAY->value,
            'note_text' => 'ملاحظة خاصة بالمعلم أ',
            'note_time' => '18:00',
            'is_done' => false,
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Teacher B tries to toggle Teacher A's note
        $this->patchJson("/api/v1/teacher/schedule/notes/{$noteA->uuid}/toggle")
            ->assertForbidden();

        // Teacher B tries to delete Teacher A's note
        $this->deleteJson("/api/v1/teacher/schedule/notes/{$noteA->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event' => 'teacher.idor_attempt_blocked',
            'level' => 'warning',
        ]);
    }

    public function test_teacher_cannot_create_or_update_study_group_with_overlapping_schedule(): void
    {
        $teacher = $this->createTeacher('teacher.overlap@motafawweq.com');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Create Group 1 on Sunday 16:00 - 18:00
        $res1 = $this->postJson('/api/v1/teacher/groups', [
            'name_ar' => 'المجموعة الأولى',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'slots' => [
                ['day_of_week' => DayOfWeek::SUNDAY->value, 'start_time' => '16:00', 'end_time' => '18:00'],
            ],
        ]);
        $res1->assertCreated();
        $group1Uuid = $res1->json('data.id');

        // Overlap against Group 1 (Sunday 17:00 - 19:00) -> 422
        $this->postJson('/api/v1/teacher/groups', [
            'name_ar' => 'المجموعة المتداخلة',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'slots' => [
                ['day_of_week' => DayOfWeek::SUNDAY->value, 'start_time' => '17:00', 'end_time' => '19:00'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);

        // Intra-group overlap (two sessions on Monday overlapping) -> 422
        $this->postJson('/api/v1/teacher/groups', [
            'name_ar' => 'تداخل داخلي',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'slots' => [
                ['day_of_week' => DayOfWeek::MONDAY->value, 'start_time' => '14:00', 'end_time' => '16:00'],
                ['day_of_week' => DayOfWeek::MONDAY->value, 'start_time' => '15:30', 'end_time' => '17:30'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);

        // Back-to-back session on Sunday 18:00 - 20:00 -> allowed!
        $res2 = $this->postJson('/api/v1/teacher/groups', [
            'name_ar' => 'المجموعة الثانية',
            'subject_ar' => 'فيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي',
            'slots' => [
                ['day_of_week' => DayOfWeek::SUNDAY->value, 'start_time' => '18:00', 'end_time' => '20:00'],
            ],
        ]);
        $res2->assertCreated();

        // Updating Group 1 with its own same slot -> allowed; updating to overlap Group 2 -> 422
        $this->putJson("/api/v1/teacher/groups/{$group1Uuid}", [
            'slots' => [
                ['day_of_week' => DayOfWeek::SUNDAY->value, 'start_time' => '16:00', 'end_time' => '18:00'],
            ],
        ])->assertOk();

        $this->putJson("/api/v1/teacher/groups/{$group1Uuid}", [
            'slots' => [
                ['day_of_week' => DayOfWeek::SUNDAY->value, 'start_time' => '18:30', 'end_time' => '19:30'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['slots']);
    }

    public function test_teacher_can_create_schedule_note_with_note_time_and_fetch_it(): void
    {
        $teacher = $this->createTeacher('teacher.notes.flow@motafawweq.com');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $createRes = $this->postJson('/api/v1/teacher/schedule/notes', [
            'day_of_week' => DayOfWeek::SUNDAY->value,
            'note_text' => 'تجهيز أوراق الامتحان',
            'note_time' => '14:30',
            'priority' => 'high',
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('data.time', '14:30')
            ->assertJsonPath('data.text', 'تجهيز أوراق الامتحان');

        $scheduleRes = $this->getJson('/api/v1/teacher/schedule');
        $scheduleRes->assertOk()
            ->assertJsonPath('data.days.sunday.notes.0.text', 'تجهيز أوراق الامتحان')
            ->assertJsonPath('data.days.sunday.notes.0.time', '14:30');
    }
}
