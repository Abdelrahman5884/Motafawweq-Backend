<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\LeagueDuration;
use App\Enums\LessonStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\V1\LeagueRulesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLeagueWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function createTeacher(string $email = 'teacher.lg@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'أ. خالد السعدني',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function createStudent(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_league_rules_credit_real_xp_from_lessons_homeworks_exams_and_sync_general_and_course_leagues(): void
    {
        $teacher = $this->createTeacher();
        $studentA = $this->createStudent('يوسف طارق علي', 'youssef.lg@motafawweq.com');
        $studentB = $this->createStudent('مريم حسن فؤاد', 'mariam.lg@motafawweq.com');
        $outsider = $this->createStudent('طالب خارجي', 'outsider.lg@motafawweq.com');

        StudentProfile::create([
            'student_id' => $studentA->id,
            'current_streak_days' => 3, // 3 * 20 = 60 streak XP
            'school_name' => 'مدرسة المتفوقين للعلوم والتكنولوجيا',
            'show_on_leaderboard' => true,
            'display_name_mode' => 'full',
        ]);

        StudentProfile::create([
            'student_id' => $studentB->id,
            'current_streak_days' => 1,
            'show_on_leaderboard' => false, // Must be masked on leaderboard
        ]);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء للثانوية العامة',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة النخبة أ',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'join_code' => 'LG-PHY-01',
            'status' => 'active',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $studentA->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
            'attendance_rate' => 92,
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $studentB->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
            'attendance_rate' => 88,
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري أبطال الفيزياء',
            'course_name_ar' => $course->title_ar,
            'group_name_ar' => 'جميع المجموعات',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
        ]);

        // Seed initial participant rows at 0 XP (student B starts slightly ahead at 40 XP)
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $studentA->id,
            'rank' => 2,
            'xp_points' => 0,
            'tier' => 'Bronze',
        ]);
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $studentB->id,
            'rank' => 1,
            'xp_points' => 40,
            'tier' => 'Bronze',
        ]);

        // Create lesson, homework, and exam under this course/teacher
        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الحصة الأولى: التيار الكهربي',
            'status' => LessonStatus::PUBLISHED,
            'duration_seconds' => 600,
            'order_index' => 1,
        ]);

        $homework = Homework::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'واجب قانون أوم',
            'max_grade' => 20,
        ]);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'امتحان الفصل الأول',
            'duration_minutes' => 30,
            'full_mark' => 10,
            'pass_score' => 5,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);

        $q1 = ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'وحدة قياس شدة التيار؟',
            'options_ar' => ['الأمبير', 'الفولت'],
            'correct_index' => 0,
        ]);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // 1. Complete lesson -> +25 XP
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/toggle-complete", [
            'is_completed' => true,
        ])->assertOk();

        // 2. Submit homework -> +15 XP
        $this->postJson("/api/v1/student/homeworks/{$homework->uuid}/submit", [
            'solution_text' => 'I = Q / t',
        ])->assertCreated();

        // 3. Submit full-mark exam -> +50 XP (Total earned in course league: 25 + 15 + 50 = 90 XP > Student B's 40 XP!)
        $this->postJson("/api/v1/student/exams/{$exam->uuid}/submit", [
            'answers' => [
                ['questionUuid' => $q1->uuid, 'selectedIndex' => 0],
            ],
        ])->assertCreated();

        // Verify course league leaderboard reflects exact rule XP, rank jump to #1, real metrics, privacy masking & rules
        $expectedCourseXp = LeagueRulesService::XP_LESSON_COMPLETED
            + LeagueRulesService::XP_HOMEWORK_SUBMITTED
            + LeagueRulesService::XP_EXAM_FULL_MARK;

        $this->getJson("/api/v1/student/leagues/{$league->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.myStanding.rank', 1)
            ->assertJsonPath('data.myStanding.xpPoints', $expectedCourseXp)
            ->assertJsonPath('data.myStanding.tier', 'Bronze')
            ->assertJsonPath('data.myStanding.nextTier', 'Silver')
            ->assertJsonPath('data.myStanding.xpToNextTier', 1900 - $expectedCourseXp)
            ->assertJsonPath('data.leaderboard.0.isCurrentUser', true)
            ->assertJsonPath('data.leaderboard.0.xpPoints', $expectedCourseXp)
            ->assertJsonPath('data.leaderboard.0.examsSolved', 1)
            ->assertJsonPath('data.leaderboard.0.perfectExams', 1)
            ->assertJsonPath('data.leaderboard.0.lessonsStudied', 1)
            ->assertJsonPath('data.leaderboard.0.homeworkRate', 100)
            ->assertJsonPath('data.leaderboard.0.schoolAr', 'مدرسة المتفوقين للعلوم والتكنولوجيا')
            ->assertJsonPath('data.leaderboard.1.displayName', 'طالب متفوق (مخفي)')
            ->assertJsonCount(6, 'data.rules')
            ->assertJsonCount(4, 'data.tiers')
            ->assertJsonCount(4, 'data.prizes');

        // 4. Verify General Republic League includes ledger XP (90) + streak bonus (3 * 20 = 60) = 150 XP!
        $expectedGeneralXp = $expectedCourseXp + (3 * LeagueRulesService::XP_STREAK_PER_DAY);

        $this->getJson('/api/v1/student/leagues/general/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.leagueId', 'general')
            ->assertJsonPath('data.myStanding.rank', 1)
            ->assertJsonPath('data.myStanding.xpPoints', $expectedGeneralXp)
            ->assertJsonPath('data.leaderboard.0.isCurrentUser', true)
            ->assertJsonPath('data.leaderboard.0.xpPoints', $expectedGeneralXp)
            ->assertJsonPath('data.leaderboard.0.streakDays', 3);

        // 5. List leagues returns General Republic League + Teacher Course League
        $this->getJson('/api/v1/student/leagues')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'general')
            ->assertJsonPath('data.1.id', $league->uuid)
            ->assertJsonPath('data.1.myRank', 1)
            ->assertJsonPath('data.1.myXp', $expectedCourseXp);

        // 6. Unenrolled outsider is strictly blocked (403 IDOR) from teacher course league
        Sanctum::actingAs($outsider, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/leagues/{$league->uuid}/leaderboard")->assertForbidden();
    }

    public function test_expired_league_closes_automatically_and_resolves_all_tier_boundaries(): void
    {
        $teacher = $this->createTeacher('teacher.exp@motafawweq.com');
        $student = $this->createStudent('أحمد حسام', 'ahmed.exp@motafawweq.com');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الكيمياء',
            'stage' => 'sec',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الكيمياء',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'join_code' => 'LG-CHEM-01',
            'status' => 'active',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);

        $expiredLeague = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري منتهي',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::WEEKLY,
            'duration_days' => 7,
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        LeagueParticipant::create([
            'course_league_id' => $expiredLeague->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 3100,
            'tier' => 'Bronze', // Stale tier should auto-fix to Diamond (>= 3000)
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $this->getJson("/api/v1/student/leagues/{$expiredLeague->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.daysRemaining', 0)
            ->assertJsonPath('data.myStanding.tier', 'Diamond')
            ->assertJsonPath('data.myStanding.nextTier', null)
            ->assertJsonPath('data.myStanding.tierProgressPercent', 100);
    }
}
