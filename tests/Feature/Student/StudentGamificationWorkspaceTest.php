<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\RepublicLeague;
use App\Models\StudentDailyActivity;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\StudentXpLedger;
use App\Models\StudyGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentGamificationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createStudent(string $name = 'أحمد محمود', string $email = 'student.gam@motafawweq.com'): User
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

    private function createTeacher(string $name = 'أ. خالد السعدني', string $email = 'teacher.gam@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_workspace_returns_sunday_aligned_heatmap_and_tracks_non_minute_activity(): void
    {
        // Wednesday 2026-11-18 14:00 Cairo
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 12, 0, 0, 'UTC'));

        $student = $this->createStudent();
        StudentProfile::create([
            'student_id' => $student->id,
            'timezone' => 'Africa/Cairo',
        ]);

        // Visit today with 30 seconds (< 1 minute) — should STILL produce level > 0 and hasVisit = true
        StudentDailyActivity::create([
            'student_id' => $student->id,
            'activity_date' => '2026-11-18',
            'active_seconds' => 30,
            'visits_count' => 1,
            'first_seen_at' => Carbon::now(),
            'last_heartbeat_at' => Carbon::now(),
        ]);

        // Longer session 2 days ago (Monday 2026-11-16)
        StudentDailyActivity::create([
            'student_id' => $student->id,
            'activity_date' => '2026-11-16',
            'active_seconds' => 3600,
            'visits_count' => 2,
            'first_seen_at' => Carbon::now()->subDays(2),
            'last_heartbeat_at' => Carbon::now()->subDays(2),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/gamification/workspace?timezone=Africa/Cairo');
        $res->assertOk();

        $heatmap = $res->json('data.heatmap');
        $this->assertCount(52, $heatmap['weeks']);
        $this->assertSame('Africa/Cairo', $heatmap['timezone']);
        $this->assertGreaterThanOrEqual(2, $heatmap['activeDays']);
        $this->assertNotEmpty($heatmap['monthMarkers']);

        // Every single week column must be Sunday (row 0) through Saturday (row 6)
        foreach ($heatmap['weeks'] as $week) {
            $this->assertCount(7, $week);
            $this->assertSame('الأحد', $week[0]['dayNameAr']);
            $this->assertSame('Sunday', $week[0]['dayNameEn']);
            $this->assertSame('السبت', $week[6]['dayNameAr']);
            $this->assertSame('Saturday', $week[6]['dayNameEn']);
        }

        // Last week (index 51): Sunday Nov 15 .. Wednesday Nov 18 (today) .. Saturday Nov 21 (future)
        $lastWeek = $heatmap['weeks'][51];
        $this->assertSame('2026-11-15', $lastWeek[0]['date']);
        $this->assertFalse($lastWeek[0]['isFuture']);

        // Monday Nov 16 (index 1): 60 active minutes
        $this->assertSame('2026-11-16', $lastWeek[1]['date']);
        $this->assertSame(60, $lastWeek[1]['minutes']);
        $this->assertGreaterThanOrEqual(3, $lastWeek[1]['level']);

        // Wednesday Nov 18 (index 3): today, 0 full minutes but visited -> level >= 1
        $this->assertSame('2026-11-18', $lastWeek[3]['date']);
        $this->assertTrue($lastWeek[3]['isToday']);
        $this->assertFalse($lastWeek[3]['isFuture']);
        $this->assertTrue($lastWeek[3]['hasVisit']);
        $this->assertGreaterThanOrEqual(1, $lastWeek[3]['level']);

        // Thursday..Saturday (indices 4..6): future days
        $this->assertTrue($lastWeek[4]['isFuture']);
        $this->assertTrue($lastWeek[5]['isFuture']);
        $this->assertTrue($lastWeek[6]['isFuture']);
    }

    public function test_weekly_challenges_compute_real_progress_award_idempotent_xp_and_never_pollute_league(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 18, 12, 0, 0, 'UTC'));

        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        StudentProfile::create([
            'student_id' => $student->id,
            'weekly_exams_goal' => 2,
            'timezone' => 'Africa/Cairo',
        ]);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء الحديثة',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
            'price_egp' => 200,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة التفوق',
            'center_name' => 'أونلاين',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'schedule_ar' => 'السبت والثلاثاء',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => Carbon::now()->subDays(5),
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'study_group_id' => $group->id,
            'title_ar' => 'دوري الفيزياء الشهري',
            'course_name_ar' => $course->title_ar,
            'group_name_ar' => $group->name_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->addDays(29),
        ]);

        // Complete 5 lessons this week -> completes wc-lessons (+50 XP)
        for ($i = 1; $i <= 5; $i++) {
            $lesson = Lesson::create([
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'title_ar' => "حصة {$i}",
                'sort_order' => $i,
                'status' => LessonStatus::PUBLISHED,
            ]);
            StudentLessonProgress::create([
                'student_id' => $student->id,
                'course_id' => $course->id,
                'lesson_id' => $lesson->id,
                'is_completed' => true,
                'watched_seconds' => 600,
                'completed_at' => Carbon::now()->subHours($i),
            ]);
        }

        // Submit 1 UNGRADED exam with percentage 100 (should count in examsDone, NOT in perfectExams!)
        $examPending = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار مقالي منتظر',
            'status' => ExamStatus::ACTIVE,
            'duration_minutes' => 30,
            'full_mark' => 10,
            'pass_mark' => 5,
        ]);
        ExamSubmission::create([
            'exam_id' => $examPending->id,
            'student_id' => $student->id,
            'started_at' => Carbon::now()->subHours(3),
            'submitted_at' => Carbon::now()->subHours(2),
            'score' => 10,
            'full_mark' => 10,
            'percentage' => 100,
            'is_passed' => true,
            'is_full_mark' => false,
            'status' => ExamSubmissionStatus::SUBMITTED,
        ]);

        // Submit 2 homeworks this week -> completes wc-homework (+30 XP)
        for ($h = 1; $h <= 2; $h++) {
            $hw = Homework::create([
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'course_title_ar' => $course->title_ar,
                'title_ar' => "واجب {$h}",
                'type' => HomeworkType::FILE,
                'max_grade' => 10,
            ]);
            HomeworkSubmission::create([
                'homework_id' => $hw->id,
                'student_id' => $student->id,
                'status' => HomeworkSubmissionStatus::PENDING,
                'solution_text' => 'الحل الكامل',
                'max_score' => 10,
                'submitted_at' => Carbon::now()->subHours($h),
            ]);
        }

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // First call: awards wc-lessons (+50) and wc-homework (+30) => totalXp = 80 immediately in summary!
        $res1 = $this->getJson('/api/v1/student/gamification/workspace?timezone=Africa/Cairo');
        $res1->assertOk();

        $challenges = collect($res1->json('data.challenges'))->keyBy('id');
        $this->assertTrue($challenges['wc-lessons']['isCompleted']);
        $this->assertTrue($challenges['wc-lessons']['completed']);
        $this->assertSame(50, $challenges['wc-lessons']['xpReward']);
        $this->assertSame('/student/courses', $challenges['wc-lessons']['link']);
        $this->assertSame('السبت', $challenges['wc-lessons']['resetDayAr']);

        $this->assertFalse($challenges['wc-exams']['isCompleted']);
        $this->assertSame(1, $challenges['wc-exams']['current']);
        $this->assertSame(2, $challenges['wc-exams']['target']);

        // Ungraded exam must NOT satisfy wc-perfect
        $this->assertFalse($challenges['wc-perfect']['isCompleted']);
        $this->assertSame(0, $challenges['wc-perfect']['current']);

        $this->assertTrue($challenges['wc-homework']['isCompleted']);
        $this->assertSame(30, $challenges['wc-homework']['xpReward']);
        $this->assertSame('/student/homework', $challenges['wc-homework']['link']);

        // Summary immediately includes newly awarded weekly challenge XP (50 + 30 = 80)
        $summary = $res1->json('data.summary');
        $this->assertSame(80, $summary['totalXp']);
        $this->assertSame(1, $summary['level']);
        $this->assertSame(80, $summary['xpInCurrentLevel']);
        $this->assertSame(20, $summary['xpRemainingForNextLevel']);
        $this->assertSame(80, $summary['levelProgressPercent']);

        // Badges reflect real DB state: first-lesson & first-exam unlocked; lesson-explorer at 5/10; perfect-score locked
        $badges = collect($summary['badges'])->keyBy('id');
        $this->assertTrue($badges['first-lesson']['unlocked']);
        $this->assertFalse($badges['lesson-explorer']['unlocked']);
        $this->assertSame(5, $badges['lesson-explorer']['current']);
        $this->assertTrue($badges['first-exam']['unlocked']);
        $this->assertFalse($badges['perfect-score']['unlocked']);

        // Calling again must NEVER double-award XP (idempotent)
        $res2 = $this->getJson('/api/v1/student/gamification/workspace?timezone=Africa/Cairo');
        $res2->assertOk();
        $this->assertSame(80, $res2->json('data.summary.totalXp'));
        $this->assertSame(2, StudentXpLedger::where('student_id', $student->id)->where('source_type', 'weekly_challenge')->count());

        // Weekly challenge XP must NEVER leak into the fresh teacher course league (stays at 0!)
        $teacherLeagueRow = collect($res2->json('data.leagues'))->firstWhere('id', $league->uuid);
        $this->assertNotNull($teacherLeagueRow);
        $this->assertSame(0, $teacherLeagueRow['myXp']);
    }

    public function test_past_leagues_auto_closes_expired_course_league_and_includes_finished_republic_league(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 12, 0, 0, 'UTC'));

        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الكيمياء العضوية',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
            'price_egp' => 150,
        ]);

        // An expired CourseLeague that is STILL marked is_active = true in DB
        $expiredLeague = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الكيمياء - أكتوبر',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => Carbon::now()->subDays(35),
            'ends_at' => Carbon::now()->subDays(5),
            'total_competitors' => 1,
        ]);

        LeagueParticipant::create([
            'course_league_id' => $expiredLeague->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 160,
            'streak_days' => 5,
            'tier' => LeagueTier::GOLD->value,
            'badge_title_ar' => 'بطل الكورس',
        ]);

        // A finished Republic League round in which the student earned streak points
        $pastRepublic = RepublicLeague::create([
            'title_ar' => 'دوري الجمهورية - الجولة الأولى',
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => false,
            'starts_at' => Carbon::now()->subDays(40),
            'ends_at' => Carbon::now()->subDays(10),
        ]);

        StudentDailyActivity::create([
            'student_id' => $student->id,
            'activity_date' => Carbon::now()->subDays(20)->toDateString(),
            'active_seconds' => 900,
            'visits_count' => 1,
            'first_seen_at' => Carbon::now()->subDays(20),
            'last_heartbeat_at' => Carbon::now()->subDays(20),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Calling /student/leagues/history directly must auto-close expiredLeague & return BOTH archives cleanly
        $res = $this->getJson('/api/v1/student/leagues/history');
        $res->assertOk();

        $expiredLeague->refresh();
        $this->assertFalse($expiredLeague->is_active);

        $past = collect($res->json('data'));
        $this->assertCount(2, $past);

        $courseEntry = $past->firstWhere('leagueId', $expiredLeague->uuid);
        $this->assertNotNull($courseEntry);
        $this->assertFalse($courseEntry['isRepublicLeague']);
        $this->assertSame('Gold', $courseEntry['tier']);
        $this->assertSame('دوري ذهبي', $courseEntry['tierAr']);
        $this->assertSame('بطل الكورس', $courseEntry['badgeAr']);
        $this->assertSame($teacher->name, $courseEntry['teacherName']);
        $this->assertSame(160, $courseEntry['points']);

        $repEntry = $past->firstWhere('isRepublicLeague', true);
        $this->assertNotNull($repEntry);
        $this->assertSame($pastRepublic->title_ar, $repEntry['titleAr']);
        $this->assertSame(1, $repEntry['rank']);
        $this->assertSame(3, $repEntry['points']); // 1 active day in window * +3 pts
        $this->assertSame('دوري برونزي', $repEntry['tierAr']);
    }

    public function test_gamification_workspace_blocks_guests_and_non_students(): void
    {
        $this->getJson('/api/v1/student/gamification/workspace')->assertUnauthorized();

        $teacher = $this->createTeacher('أ. سارة', 'teacher.block@motafawweq.com');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/student/gamification/workspace')->assertForbidden();
    }
}
