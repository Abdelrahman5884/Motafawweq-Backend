<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\LeagueDuration;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
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
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLeagueWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        $student = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PLUS,
            'plan_name_ar' => StudentPlanTier::PLUS->labelAr(),
            'plan_name_en' => StudentPlanTier::PLUS->labelEn(),
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => 50,
            'starts_at' => now(),
            'renews_at' => now()->addDays(30),
            'status' => 'active',
        ]);

        return $student;
    }

    public function test_league_rules_enforce_exact_question_full_mark_essay_and_daily_streak_points(): void
    {
        $teacher = $this->createTeacher();
        $studentA = $this->createStudent('يوسف طارق علي', 'youssef.lg@motafawweq.com');
        $studentB = $this->createStudent('مريم حسن فؤاد', 'mariam.lg@motafawweq.com');
        $outsider = $this->createStudent('طالب خارجي', 'outsider.lg@motafawweq.com');

        StudentProfile::create([
            'student_id' => $studentA->id,
            'current_streak_days' => 3, // 3 * 3 = 9 streak pts
            'school_name' => 'مدرسة المتفوقين للعلوم والتكنولوجيا',
            'show_on_leaderboard' => true,
            'display_name_mode' => 'full',
        ]);

        StudentProfile::create([
            'student_id' => $studentB->id,
            'current_streak_days' => 1, // 1 * 3 = 3 streak pts
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

        // Seed initial participant rows (student B starts ahead at 10 pts)
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $studentA->id,
            'rank' => 2,
            'xp_points' => 0,
            'streak_days' => 3,
            'tier' => 'Bronze',
        ]);
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $studentB->id,
            'rank' => 1,
            'xp_points' => 10,
            'streak_days' => 1,
            'tier' => 'Bronze',
        ]);

        // Create lesson, homework, objective exam (MCQ + True/False), and essay exam
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

        $objectiveExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'امتحان الفصل الأول',
            'duration_minutes' => 30,
            'full_mark' => 20,
            'pass_score' => 10,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);

        $qMcq = ExamQuestion::create([
            'exam_id' => $objectiveExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'وحدة قياس شدة التيار؟',
            'options_ar' => ['الأمبير', 'الفولت'],
            'correct_index' => 0,
        ]);

        $qTf = ExamQuestion::create([
            'exam_id' => $objectiveExam->id,
            'question_number' => 2,
            'type' => ExamQuestionType::TRUE_FALSE,
            'points' => 10,
            'question_ar' => 'يقاس فرق الجهد بالفولتميتر.',
            'correct_bool' => true,
        ]);

        $essayExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'امتحان مقالي شامل',
            'duration_minutes' => 20,
            'full_mark' => 10,
            'pass_score' => 5,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);

        $qEssay = ExamQuestion::create([
            'exam_id' => $essayExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::ESSAY,
            'points' => 10,
            'question_ar' => 'اشرح قانون كيرشوف الأول.',
            'model_answer_ar' => 'مجموع التيارات الداخلة يساوي الخارجة.',
        ]);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // 1. Completing lesson & submitting homework do NOT add league points (only the 5 official rules count!)
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/toggle-complete", [
            'is_completed' => true,
        ])->assertOk();

        $this->postJson("/api/v1/student/homeworks/{$homework->uuid}/submit", [
            'solution_text' => 'I = Q / t',
        ])->assertCreated();

        // 2. Submit objective exam with 100% Full Mark:
        //    MCQ (+3) + True/False (+1) + Full Mark Bonus (+3) = 7 pts!
        $this->postJson("/api/v1/student/exams/{$objectiveExam->uuid}/submit", [
            'answers' => [
                ['questionUuid' => $qMcq->uuid, 'selectedIndex' => 0],
                ['questionUuid' => $qTf->uuid, 'selectedBool' => true],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.xpAwarded', 7);

        // 3. Submit essay exam -> 0 pts before teacher grades it
        $essaySubRes = $this->postJson("/api/v1/student/exams/{$essayExam->uuid}/submit", [
            'answers' => [
                ['questionUuid' => $qEssay->uuid, 'essayAnswerText' => 'مجموع التيارات عند نقطة تفرع يساوي صفر.'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.xpAwarded', 0);

        $essaySubmissionUuid = $essaySubRes->json('data.id');

        // 4. Teacher grades essay as fully correct (10/10 = Full Mark):
        //    Essay (+5) + Full Mark Bonus (+3) = 8 pts credited automatically to active league!
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->patchJson("/api/v1/teacher/exams/{$essayExam->uuid}/submissions/{$essaySubmissionUuid}/grade-essay", [
            'answers' => [
                ['question_uuid' => $qEssay->uuid, 'points_awarded' => 10],
            ],
            'teacher_feedback' => 'ممتاز',
        ])->assertOk();

        // Total Course League pts earned by Student A = 7 + 8 = 15 pts (> Student B's 10 pts -> Rank #1!)
        $expectedCoursePts = (LeagueRulesService::XP_QUESTION_MCQ + LeagueRulesService::XP_QUESTION_TRUE_FALSE + LeagueRulesService::XP_EXAM_FULL_MARK_BONUS)
            + (LeagueRulesService::XP_QUESTION_ESSAY + LeagueRulesService::XP_EXAM_FULL_MARK_BONUS);
        $this->assertEquals(15, $expectedCoursePts);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // Re-fetching essay exam result shows complete 8 pts!
        $this->getJson("/api/v1/student/exams/{$essayExam->uuid}/result")
            ->assertOk()
            ->assertJsonPath('data.xpAwarded', 8);

        $this->getJson("/api/v1/student/leagues/{$league->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.myStanding.rank', 1)
            ->assertJsonPath('data.myStanding.xpPoints', 15)
            ->assertJsonPath('data.myStanding.tier', 'Bronze')
            ->assertJsonPath('data.myStanding.nextTier', 'Silver')
            ->assertJsonPath('data.myStanding.xpToNextTier', 51)
            ->assertJsonPath('data.tiers.0.minXp', 251)
            ->assertJsonPath('data.tiers.1.minXp', 151)
            ->assertJsonPath('data.tiers.2.minXp', 66)
            ->assertJsonPath('data.tiers.3.minXp', 0)
            ->assertJsonPath('data.tiers.3.maxXp', 65)
            ->assertJsonPath('data.leaderboard.0.isCurrentUser', true)
            ->assertJsonPath('data.leaderboard.0.xpPoints', 15)
            ->assertJsonPath('data.leaderboard.0.examsSolved', 2)
            ->assertJsonPath('data.leaderboard.0.perfectExams', 2)
            ->assertJsonPath('data.leaderboard.0.lessonsStudied', 1)
            ->assertJsonPath('data.leaderboard.0.homeworkRate', 100)
            ->assertJsonPath('data.leaderboard.1.displayName', 'طالب متفوق (مخفي)')
            ->assertJsonCount(5, 'data.rules')
            ->assertJsonPath('data.rules.0.points', 5)
            ->assertJsonPath('data.rules.1.points', 3)
            ->assertJsonPath('data.rules.2.points', 1)
            ->assertJsonPath('data.rules.3.points', 3)
            ->assertJsonPath('data.rules.4.points', 3);

        // 5. General Republic League has NOT started yet (only Admin can start it!)
        $this->getJson('/api/v1/student/leagues/general/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.leagueId', 'general')
            ->assertJsonPath('data.hasStarted', false)
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.totalCompetitors', 0)
            ->assertJsonCount(0, 'data.leaderboard');

        // Student cannot start Republic League (403)
        $this->postJson('/api/v1/admin/leagues/republic/start')->assertForbidden();

        // Admin starts a fresh General Republic League NOW -> everyone starts at 0 pts (pre-start exams do NOT count!)
        $admin = User::factory()->create([
            'name' => 'مدير المنصة',
            'email' => 'admin.lg@motafawweq.com',
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Carbon::setTestNow(now()->addMinute());
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'دوري الجمهورية العام — الجولة الأولى',
            'duration' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.isActive', true);

        // Immediately after Admin starts Republic League, Student A starts cleanly at 0 pts (old exams ignored!)
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/leagues/general/leaderboard')
            ->assertOk()
            ->assertJsonPath('data.hasStarted', true)
            ->assertJsonPath('data.isActive', true)
            ->assertJsonPath('data.myStanding.xpPoints', 0);

        // Recreating a brand-new teacher Course League ALSO starts everyone cleanly at 0 pts (NO old points carried over!)
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $freshLeagueRes = $this->postJson('/api/v1/teacher/leagues', [
            'courseId' => $course->uuid,
            'duration' => 'monthly',
            'titleAr' => 'دوري جديد من الصفر',
        ])->assertCreated();

        $freshLeagueUuid = $freshLeagueRes->json('data.id');
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/leagues/{$freshLeagueUuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.myStanding.xpPoints', 0)
            ->assertJsonPath('data.leaderboard.0.xpPoints', 0);

        // 6. Unenrolled outsider is blocked (403 IDOR)
        Sanctum::actingAs($outsider, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/leagues/{$league->uuid}/leaderboard")->assertForbidden();
    }

    public function test_daily_streak_increment_awards_3_pts_in_active_league_and_expired_league_closes(): void
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

        $activeLeague = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الكيمياء النشط',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => Carbon::create(2026, 11, 1, 0, 0, 0, 'Africa/Cairo'),
            'ends_at' => Carbon::create(2026, 12, 1, 0, 0, 0, 'Africa/Cairo'),
        ]);

        $participant = LeagueParticipant::create([
            'course_league_id' => $activeLeague->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 0,
            'streak_days' => 0,
            'tier' => 'Bronze',
        ]);

        $expiredLeague = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري منتهي',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::WEEKLY,
            'duration_days' => 7,
            'is_active' => true,
            'starts_at' => Carbon::create(2026, 10, 1, 0, 0, 0, 'Africa/Cairo'),
            'ends_at' => Carbon::create(2026, 10, 8, 0, 0, 0, 'Africa/Cairo'),
        ]);

        LeagueParticipant::create([
            'course_league_id' => $expiredLeague->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 260,
            'tier' => 'Bronze', // Stale tier should auto-fix to Diamond (>= 251)
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Open platform Day 1 -> streak = 1 -> +3 pts in active league
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 10, 0, 0, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 0,
            'timezone' => 'Africa/Cairo',
            'is_check_in' => true,
        ])->assertOk();
        $this->assertEquals(3, $participant->fresh()->xp_points);

        // Open platform Day 2 -> streak = 2 -> +3 more pts = 6 pts total
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 10, 0, 0, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 0,
            'timezone' => 'Africa/Cairo',
            'is_check_in' => true,
        ])->assertOk();
        $this->assertEquals(6, $participant->fresh()->xp_points);

        // Expired league closes automatically and resolves Diamond tier
        $this->getJson("/api/v1/student/leagues/{$expiredLeague->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.daysRemaining', 0)
            ->assertJsonPath('data.myStanding.tier', 'Diamond')
            ->assertJsonPath('data.myStanding.nextTier', null)
            ->assertJsonPath('data.myStanding.tierProgressPercent', 100);
    }
}
