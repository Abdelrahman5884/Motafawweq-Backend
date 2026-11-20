<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LessonStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentDailyActivity;
use App\Models\StudentLessonProgress;
use App\Models\StudentStudySession;
use App\Models\StudentXpLedger;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\V1\Student\StudentActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDashboardAndStreakTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createStudent(string $email = 'streak.student@motafawweq.com', string $name = 'يوسف المتفوق'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(10000, 99999),
        ]);
    }

    protected function createTeacher(string $email = 'streak.teacher@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'أ. كريم الفيزياء',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_daily_streak_increments_on_consecutive_days_and_resets_when_a_day_is_missed(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري المتفوقين',
            'course_title_ar' => 'الفيزياء',
        ]);

        $participant = LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'xp_points' => 100,
            'streak_days' => 0,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Day 1: 2026-11-10 10:00 Cairo
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 10, 0, 0, 'Africa/Cairo'));
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 1)
            ->assertJsonPath('data.kpis.longestStreakDays', 1)
            ->assertJsonPath('data.kpis.isActiveToday', true)
            ->assertJsonPath('data.kpis.streakWasReset', false);

        // Same Day 1 later (18:00 Cairo): must NOT increment streak again
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 18, 0, 0, 'Africa/Cairo'));
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 1)
            ->assertJsonPath('data.kpis.longestStreakDays', 1);

        // Day 2: 2026-11-11 09:00 Cairo -> streak = 2
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 9, 0, 0, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 0,
            'timezone' => 'Africa/Cairo',
            'is_check_in' => true,
        ])->assertOk()
            ->assertJsonPath('data.streakDays', 2)
            ->assertJsonPath('data.longestStreakDays', 2)
            ->assertJsonPath('data.streakWasReset', false);

        // Day 3: 2026-11-12 22:30 Cairo -> streak = 3 (synced to LeagueParticipant too)
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 22, 30, 0, 'Africa/Cairo'));
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 3)
            ->assertJsonPath('data.kpis.longestStreakDays', 3);

        $this->assertEquals(3, $participant->fresh()->streak_days);

        // SKIP Day 4 (2026-11-13) completely!
        // On Day 5 (2026-11-14 08:00 Cairo), BEFORE student opens platform: read-only snapshot reports streak = 0!
        Carbon::setTestNow(Carbon::create(2026, 11, 14, 8, 0, 0, 'Africa/Cairo'));
        /** @var StudentActivityService $activityService */
        $activityService = app(StudentActivityService::class);
        $snapshotBeforeOpen = $activityService->getActivitySnapshot($student, 'Africa/Cairo');
        $this->assertEquals(0, $snapshotBeforeOpen['streakDays']);
        $this->assertEquals(3, $snapshotBeforeOpen['longestStreakDays']);
        $this->assertFalse($snapshotBeforeOpen['isActiveToday']);

        // Now student opens dashboard on Day 5 -> old 3-day streak is gone; starts fresh at 1 while longest stays 3!
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 1)
            ->assertJsonPath('data.kpis.longestStreakDays', 3)
            ->assertJsonPath('data.kpis.streakWasReset', true)
            ->assertJsonPath('data.kpis.previousStreakDays', 3);

        $this->assertEquals(1, $participant->fresh()->streak_days);

        // Day 6: 2026-11-15 -> fresh streak grows to 2, longest remains 3
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 12, 0, 0, 'Africa/Cairo'));
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 2)
            ->assertJsonPath('data.kpis.longestStreakDays', 3)
            ->assertJsonPath('data.kpis.streakWasReset', false);
    }

    public function test_daily_active_time_tracks_accurately_caps_anti_cheat_and_rolls_over_at_midnight(): void
    {
        $student = $this->createStudent('timer.student@motafawweq.com');
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Start at 23:58:00 Cairo on 2026-11-20
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 23, 58, 0, 'Africa/Cairo'));

        // Check-in (0s)
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 0,
            'timezone' => 'Africa/Cairo',
            'is_check_in' => true,
        ])->assertOk()
            ->assertJsonPath('data.activeSecondsToday', 0)
            ->assertJsonPath('data.activeMinutesToday', 0)
            ->assertJsonPath('data.todayDate', '2026-11-20');

        // Concurrent tab check-in 10s later must NOT clobber baseline
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 23, 58, 10, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 0,
            'timezone' => 'Africa/Cairo',
            'is_check_in' => true,
        ])->assertOk()
            ->assertJsonPath('data.activeSecondsToday', 0);

        // Real heartbeat 30s after original check-in -> full 30s credited
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 23, 58, 30, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 30,
            'timezone' => 'Africa/Cairo',
        ])->assertOk()
            ->assertJsonPath('data.activeSecondsToday', 30)
            ->assertJsonPath('data.activeMinutesToday', 0);

        // Duplicate / spoofed heartbeat just 2 seconds later asking for 90s -> strictly capped to 2s!
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 23, 58, 32, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 90,
            'timezone' => 'Africa/Cairo',
        ])->assertOk()
            ->assertJsonPath('data.activeSecondsToday', 32);

        // Normal 60s later -> credited 60s => total 92s => 1 full minute
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 23, 59, 32, 'Africa/Cairo'));
        $this->postJson('/api/v1/student/activity/heartbeat', [
            'delta_seconds' => 60,
            'timezone' => 'Africa/Cairo',
        ])->assertOk()
            ->assertJsonPath('data.activeSecondsToday', 92)
            ->assertJsonPath('data.activeMinutesToday', 1);

        // Cross midnight to 2026-11-21 00:01:00 Cairo!
        Carbon::setTestNow(Carbon::create(2026, 11, 21, 0, 1, 0, 'Africa/Cairo'));
        $dashRes = $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 2)
            ->assertJsonPath('data.kpis.studySecondsToday', 0)
            ->assertJsonPath('data.kpis.studyMinutesToday', 0)
            ->assertJsonPath('data.activity.todayDate', '2026-11-21');

        // Verify 7-day chart ends on today (2026-11-21) with yesterday (2026-11-20) showing 1 minute
        $weekly = $dashRes->json('data.weeklyActivity');
        $this->assertCount(7, $weekly);
        $this->assertEquals('2026-11-20', $weekly[5]['date']);
        $this->assertEquals(1, $weekly[5]['minutes']);
        $this->assertFalse($weekly[5]['isToday']);
        $this->assertEquals('2026-11-21', $weekly[6]['date']);
        $this->assertEquals(0, $weekly[6]['minutes']);
        $this->assertTrue($weekly[6]['isToday']);
    }

    public function test_dashboard_returns_accurate_batch_rank_subject_stats_and_deep_linked_tasks(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 14, 0, 0, 'Africa/Cairo')); // Friday

        $teacher = $this->createTeacher('dash.teacher@motafawweq.com');
        $student = $this->createStudent('dash.student@motafawweq.com');
        $topStudent1 = $this->createStudent('top1@motafawweq.com', 'سلمى الأولى');
        $topStudent2 = $this->createStudent('top2@motafawweq.com', 'طارق الثاني');

        // Top student 1 has 4 separate XP entries totaling 200 XP
        StudentXpLedger::awardOnce($topStudent1->id, 'lesson_completed', 1, 50, 'درس 1');
        StudentXpLedger::awardOnce($topStudent1->id, 'lesson_completed', 2, 50, 'درس 2');
        StudentXpLedger::awardOnce($topStudent1->id, 'lesson_completed', 3, 50, 'درس 3');
        StudentXpLedger::awardOnce($topStudent1->id, 'lesson_completed', 4, 50, 'درس 4');

        // Top student 2 has 3 separate XP entries totaling 150 XP
        StudentXpLedger::awardOnce($topStudent2->id, 'lesson_completed', 1, 50, 'درس 1');
        StudentXpLedger::awardOnce($topStudent2->id, 'lesson_completed', 2, 50, 'درس 2');
        StudentXpLedger::awardOnce($topStudent2->id, 'lesson_completed', 3, 50, 'درس 3');

        // Our student has 100 XP -> MUST be rank #3 (NOT #5 from broken groupBy count!)
        StudentXpLedger::awardOnce($student->id, 'lesson_completed', 1, 60, 'درس 1');
        StudentXpLedger::awardOnce($student->id, 'exam_submitted', 1, 40, 'امتحان 1');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء الحديثة',
            'title_en' => 'Modern Physics',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson1 = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'الحصة الأولى: التيار الكهربي',
            'title_en' => 'Lesson 1: Electric Current',
            'duration_seconds' => 1800,
            'duration_label' => '30:00',
            'status' => LessonStatus::PUBLISHED,
        ]);

        $lesson2 = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'الحصة الثانية: قانون أوم',
            'title_en' => 'Lesson 2: Ohm Law',
            'duration_seconds' => 2400,
            'duration_label' => '40:00',
            'status' => LessonStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الفيزياء',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة 1',
            'price_egp' => 400,
            'join_code' => 'PHY-2026',
            'status' => 'active',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);

        // Completed lesson 1, in-progress lesson 2
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson1->id,
            'watched_seconds' => 1800,
            'max_position_seconds' => 1800,
            'duration_seconds' => 1800,
            'progress_percent' => 100,
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson2->id,
            'watched_seconds' => 600,
            'max_position_seconds' => 600,
            'duration_seconds' => 2400,
            'progress_percent' => 25,
            'is_completed' => false,
        ]);

        // Graded 100% exam + 1 upcoming active exam + 1 pending homework
        $doneExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار الفصل الأول',
            'duration_minutes' => 20,
            'full_mark' => 20,
            'pass_score' => 10,
            'status' => ExamStatus::ACTIVE,
        ]);

        ExamSubmission::create([
            'exam_id' => $doneExam->id,
            'student_id' => $student->id,
            'score' => 20,
            'full_mark' => 20,
            'percentage' => 100.0,
            'is_passed' => true,
            'is_full_mark' => true,
            'status' => ExamSubmissionStatus::GRADED,
            'submitted_at' => now(),
            'graded_at' => now(),
        ]);

        $pendingExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار الفصل الثاني',
            'title_en' => 'Chapter 2 Exam',
            'duration_minutes' => 30,
            'full_mark' => 50,
            'pass_score' => 25,
            'due_date' => '2026-11-25',
            'status' => ExamStatus::ACTIVE,
        ]);

        $pendingHw = Homework::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'واجب قانون أوم',
            'max_grade' => 20,
            'due_date' => '2026-11-23',
        ]);

        // Personal study session scheduled for today (Friday)
        $session = StudentStudySession::create([
            'student_id' => $student->id,
            'day_of_week' => DayOfWeek::FRIDAY->value,
            'subject_ar' => 'الفيزياء الحديثة',
            'title_ar' => 'مراجعة مسائل أوم',
            'start_time' => '18:00',
            'end_time' => '19:30',
            'duration_minutes' => 90,
            'is_completed' => false,
        ]);

        // Also seed 25 minutes of active platform time today
        StudentDailyActivity::create([
            'student_id' => $student->id,
            'activity_date' => '2026-11-20',
            'active_seconds' => 1500,
            'visits_count' => 1,
            'first_seen_at' => now()->subMinutes(25),
            'last_heartbeat_at' => now()->subSeconds(5),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.batchRank', 3)
            ->assertJsonPath('data.kpis.totalXp', 100)
            ->assertJsonPath('data.kpis.completedLessons', 1)
            ->assertJsonPath('data.kpis.totalEnrolledLessons', 2)
            ->assertJsonPath('data.kpis.examsCompletedCount', 1)
            ->assertJsonPath('data.kpis.fullMarkExamsCount', 1)
            ->assertJsonPath('data.kpis.studyMinutesToday', 25)
            ->assertJsonPath('data.kpis.studySecondsToday', 1500)
            ->assertJsonPath('data.subjectMastery.0.examsCompletedCount', 1)
            ->assertJsonPath('data.subjectMastery.0.fullMarkExamsCount', 1)
            ->assertJsonPath('data.continueLesson.lessonId', $lesson2->uuid)
            ->assertJsonPath('data.continueLesson.pausedMinute', '10:00')
            ->assertJsonPath('data.upcomingDeadlines.0.id', $pendingExam->uuid)
            ->assertJsonPath('data.upcomingHomeworks.0.id', $pendingHw->uuid);

        $tasks = collect($res->json('data.todayTasks'));
        $this->assertTrue($tasks->contains(fn ($t) => $t['id'] === 'session-'.$session->uuid && $t['source'] === 'study_session'));
        $this->assertTrue($tasks->contains(fn ($t) => $t['id'] === 'exam-'.$pendingExam->uuid && $t['actionRoute'] === "/student/exam?examId={$pendingExam->uuid}"));
        $this->assertTrue($tasks->contains(fn ($t) => $t['id'] === 'hw-'.$pendingHw->uuid && $t['actionRoute'] === "/student/homework?homeworkId={$pendingHw->uuid}"));

        // Non-student cannot access heartbeat
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->postJson('/api/v1/student/activity/heartbeat', ['delta_seconds' => 30])->assertForbidden();
    }
}
