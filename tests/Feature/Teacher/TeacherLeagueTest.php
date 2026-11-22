<?php

namespace Tests\Feature\Teacher;

use App\Enums\EnrollmentStatus;
use App\Enums\LeagueDuration;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\GroupEnrollment;
use App\Models\LeagueParticipant;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherLeagueTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.league@motafawweq.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
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

    public function test_teacher_can_create_league_for_course_and_auto_enroll_students(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'ماستر كلاس الأحياء 3 ثانوي',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'سنتر الدقي النخبة',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
            'subject_ar' => 'الأحياء',
            'center_name' => 'سنتر الدقي النخبة',
            'governorate' => 'الجيزة',
            'area' => 'الدقي',
            'join_code' => 'BIO-DK-01',
            'max_capacity' => 50,
            'enrolled_count' => 2,
            'is_active' => true,
        ]);

        $std1 = $this->createStudent('سارة خالد منصور', 'sara@motafawweq.com');
        $std2 = $this->createStudent('عمر طارق القاضي', 'omar@motafawweq.com');

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $std1->id,
            'status' => EnrollmentStatus::ENROLLED,
            'streak_days' => 10,
            'attendance_rate' => 100,
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $std2->id,
            'status' => EnrollmentStatus::ENROLLED,
            'streak_days' => 5,
            'attendance_rate' => 95,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'courseId' => $course->uuid,
            'studyGroupId' => $group->uuid,
            'duration' => 'monthly',
        ];

        $response = $this->postJson('/api/v1/teacher/leagues', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.courseNameAr', 'ماستر كلاس الأحياء 3 ثانوي')
            ->assertJsonPath('data.groupNameAr', 'سنتر الدقي النخبة')
            ->assertJsonPath('data.durationDays', 30)
            ->assertJsonPath('data.totalCompetitors', 2);

        $this->assertDatabaseHas('course_leagues', [
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
        ]);

        $this->assertDatabaseHas('league_participants', [
            'student_id' => $std1->id,
            'rank' => 1,
        ]);
    }

    public function test_teacher_can_list_leagues_and_show_details(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس المراجعة النهائية',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري معسكر المراجعة',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'total_competitors' => 1,
        ]);

        $student = $this->createStudent('مريم عادل', 'mariam@motafawweq.com');

        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 3500,
            'tier' => 'Diamond',
            'badge_title_ar' => 'بطلة الكورس',
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // List
        $listResponse = $this->getJson('/api/v1/teacher/leagues');
        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.leagueTitle', 'دوري معسكر المراجعة');

        // Show
        $showResponse = $this->getJson("/api/v1/teacher/leagues/{$league->uuid}");
        $showResponse->assertOk()
            ->assertJsonPath('data.podium.0.nameAr', 'مريم عادل')
            ->assertJsonPath('data.podium.0.score', 3500)
            ->assertJsonPath('data.podium.0.tier', 'Diamond');
    }

    public function test_teacher_can_award_bonus_xp_and_recalculate_ranks(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الأحياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري التحدي',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'total_competitors' => 2,
        ]);

        $stdA = $this->createStudent('سارة منصور', 'sara.award@motafawweq.com');
        $stdB = $this->createStudent('عمر القاضي', 'omar.award@motafawweq.com');

        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $stdA->id,
            'rank' => 1,
            'xp_points' => 100,
            'tier' => 'Silver',
        ]);

        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $stdB->id,
            'rank' => 2,
            'xp_points' => 50,
            'tier' => 'Bronze',
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Award 220 XP to Student B so their total becomes 270 (Diamond >= 251, > 100 of Student A)
        $payload = [
            'studentId' => $stdB->id,
            'xpAmount' => 220,
            'reasonAr' => 'جائزة التميز في الواجب والامتحان',
        ];

        $response = $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/award-xp", $payload);

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Student B is now rank 1!
        $this->assertDatabaseHas('league_participants', [
            'course_league_id' => $league->id,
            'student_id' => $stdB->id,
            'rank' => 1,
            'previous_rank' => 2,
            'movement' => 'up',
            'tier' => 'Diamond',
            'badge_title_ar' => 'بطل الكورس',
        ]);

        // Student A moved down to rank 2!
        $this->assertDatabaseHas('league_participants', [
            'course_league_id' => $league->id,
            'student_id' => $stdA->id,
            'rank' => 2,
            'movement' => 'down',
            'tier' => 'Silver',
            'badge_title_ar' => 'وصيف الكورس',
        ]);
    }

    public function test_teacher_can_recalculate_and_delete_league(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الحذف',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري للحذف',
            'duration_type' => LeagueDuration::WEEKLY,
            'duration_days' => 7,
            'is_active' => true,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Recalculate
        $recalcResponse = $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/recalculate");
        $recalcResponse->assertOk();

        // Delete
        $deleteResponse = $this->deleteJson("/api/v1/teacher/leagues/{$league->uuid}");
        $deleteResponse->assertOk();

        $this->assertSoftDeleted('course_leagues', [
            'id' => $league->id,
        ]);
    }

    public function test_anti_idor_prevents_teacher_from_viewing_or_modifying_another_teachers_league(): void
    {
        $teacherA = $this->createTeacher('teacher.la@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.lb@motafawweq.com');

        $courseA = Course::create([
            'teacher_id' => $teacherA->id,
            'title_ar' => 'كورس المدرس أ',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $leagueA = CourseLeague::create([
            'teacher_id' => $teacherA->id,
            'course_id' => $courseA->id,
            'title_ar' => 'دوري أ',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Attempt view
        $showResponse = $this->getJson("/api/v1/teacher/leagues/{$leagueA->uuid}");
        $showResponse->assertForbidden();

        // Attempt award XP
        $awardResponse = $this->postJson("/api/v1/teacher/leagues/{$leagueA->uuid}/award-xp", [
            'studentId' => 1,
            'xpAmount' => 500,
        ]);
        $awardResponse->assertForbidden();

        // Attempt delete
        $delResponse = $this->deleteJson("/api/v1/teacher/leagues/{$leagueA->uuid}");
        $delResponse->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event' => 'teacher.idor_attempt_blocked',
        ]);
    }

    public function test_teacher_can_update_existing_league(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الأحياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري قديم',
            'group_name_ar' => 'جميع المجموعات (دوري موحد)',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->putJson("/api/v1/teacher/leagues/{$league->uuid}", [
            'title_ar' => 'دوري الأحياء المعدل',
            'duration' => 'weekly',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.leagueTitle', 'دوري الأحياء المعدل')
            ->assertJsonPath('data.durationType', 'weekly')
            ->assertJsonPath('data.isActive', false);

        $this->assertDatabaseHas('course_leagues', [
            'id' => $league->id,
            'title_ar' => 'دوري الأحياء المعدل',
            'duration_type' => 'weekly',
            'is_active' => false,
        ]);
    }

    public function test_teacher_can_add_student_to_started_league(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الأحياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الأحياء',
            'group_name_ar' => 'جميع المجموعات (دوري موحد)',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'total_competitors' => 0,
        ]);

        $lateStudent = $this->createStudent('يوسف متأخر', 'youssef.late@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/participants", [
            'student_id' => (string) $lateStudent->id,
            'initial_xp' => 1250,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totalCompetitors', 1)
            ->assertJsonPath('data.leaderboard.0.nameAr', 'يوسف متأخر')
            ->assertJsonPath('data.leaderboard.0.score', 1250);

        $this->assertDatabaseHas('league_participants', [
            'course_league_id' => $league->id,
            'student_id' => $lateStudent->id,
            'xp_points' => 1250,
        ]);
    }

    public function test_teacher_can_remove_and_readd_participant_and_recalculate_does_not_reenroll(): void
    {
        $teacher = $this->createTeacher('teacher.rem@motafawweq.com');
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الفيزياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الفيزياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
            'subject_ar' => 'الفيزياء',
            'center_name' => 'سنتر النخبة',
            'governorate' => 'القاهرة',
            'area' => 'المعادي',
            'join_code' => 'PHY-REM-01',
            'max_capacity' => 50,
            'enrolled_count' => 1,
            'is_active' => true,
        ]);

        $student = $this->createStudent('أحمد سامي', 'ahmed.rem@motafawweq.com');
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $createRes = $this->postJson('/api/v1/teacher/leagues', [
            'course_id' => $course->uuid,
            'study_group_id' => $group->uuid,
            'duration' => 'monthly',
        ]);
        $createRes->assertCreated()
            ->assertJsonPath('data.totalCompetitors', 1);

        $leagueUuid = $createRes->json('data.id');
        $participantUuid = $createRes->json('data.leaderboard.0.id');

        // Remove participant
        $removeRes = $this->deleteJson("/api/v1/teacher/leagues/{$leagueUuid}/participants/{$participantUuid}");
        $removeRes->assertOk()
            ->assertJsonPath('data.totalCompetitors', 0)
            ->assertJsonCount(0, 'data.leaderboard');

        // Recalculate must NOT re-enroll the manually removed student
        $recalcRes = $this->postJson("/api/v1/teacher/leagues/{$leagueUuid}/recalculate");
        $recalcRes->assertOk()
            ->assertJsonPath('data.totalCompetitors', 0);

        // Explicitly re-adding restores the participant cleanly without unique constraint collision
        $readdRes = $this->postJson("/api/v1/teacher/leagues/{$leagueUuid}/participants", [
            'student_id' => (string) $student->id,
            'initial_xp' => 150,
        ]);
        $readdRes->assertCreated()
            ->assertJsonPath('data.totalCompetitors', 1)
            ->assertJsonPath('data.leaderboard.0.score', 150);
    }

    public function test_award_xp_supports_xp_points_alias_and_rejects_non_students(): void
    {
        $teacher = $this->createTeacher('teacher.xp@motafawweq.com');
        $otherTeacher = $this->createTeacher('teacher.other@motafawweq.com');
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الكيمياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الكيمياء',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $student = $this->createStudent('ندى محمود', 'nada.xp@motafawweq.com');

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Award XP using xp_points + reason (exact payload from frontend teacherApi.js)
        $res = $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/award-xp", [
            'student_id' => (string) $student->id,
            'xp_points' => 180,
            'reason' => 'مشاركة مميزة في الحصة',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.leaderboard.0.score', 180)
            ->assertJsonPath('data.leaderboard.0.tier', 'Gold')
            ->assertJsonStructure([
                'data' => ['scoringRules', 'tiers'],
            ]);

        // Reject awarding XP to a non-student account
        $nonStudentRes = $this->postJson("/api/v1/teacher/leagues/{$league->uuid}/award-xp", [
            'student_id' => (string) $otherTeacher->id,
            'xp_points' => 100,
        ]);
        $nonStudentRes->assertStatus(422);
    }
}
