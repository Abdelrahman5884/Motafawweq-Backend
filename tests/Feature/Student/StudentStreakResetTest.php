<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\GroupEnrollment;
use App\Models\LeagueParticipant;
use App\Models\StudentDailyActivity;
use App\Models\StudentProfile;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\V1\LeagueRulesService;
use App\Services\V1\Parent\ParentPortalService;
use App\Services\V1\Student\StudentActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentStreakResetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createVerifiedUser(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_missing_a_full_calendar_day_resets_streak_to_zero_and_restarts_from_day_one_on_return(): void
    {
        $tz = 'Africa/Cairo';
        $student = $this->createVerifiedUser(UserRole::STUDENT);
        $parent = $this->createVerifiedUser(UserRole::PARENT);
        $teacher = $this->createVerifiedUser(UserRole::TEACHER);
        $parent->children()->attach($student->id);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'مقرر الأحياء',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 250,
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الأحياء',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'status' => 'active',
        ]);

        // Deliberately give enrollment an old attendance streak of 9 to verify it NEVER masks missed platform days
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'streak_days' => 9,
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'duration' => LeagueDuration::MONTHLY,
            'is_active' => true,
            'starts_at' => Carbon::create(2026, 11, 1, 0, 0, 0, $tz),
            'ends_at' => Carbon::create(2026, 11, 30, 23, 59, 59, $tz),
        ]);

        $participant = LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 30,
            'streak_days' => 0,
            'badge_title_ar' => 'منافس نشط',
            'tier' => LeagueTier::BRONZE->value,
        ]);

        /** @var StudentActivityService $activityService */
        $activityService = app(StudentActivityService::class);

        // Day 1 (Nov 10): opens platform -> streak = 1
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 14, 0, 0, $tz));
        $day1 = $activityService->recordHeartbeat($student, 15, $tz, true);
        $this->assertSame(1, $day1['streakDays']);
        $this->assertFalse($day1['streakWasReset']);

        // Same Day 1 (Nov 10 later): stays at 1
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 18, 0, 0, $tz));
        $day1Again = $activityService->recordHeartbeat($student, 30, $tz, true);
        $this->assertSame(1, $day1Again['streakDays']);

        // Day 2 (Nov 11): consecutive -> streak = 2
        Carbon::setTestNow(Carbon::create(2026, 11, 11, 12, 0, 0, $tz));
        $day2 = $activityService->recordHeartbeat($student, 20, $tz, true);
        $this->assertSame(2, $day2['streakDays']);
        $this->assertFalse($day2['streakWasReset']);

        // Day 3 (Nov 12): consecutive -> streak = 3
        Carbon::setTestNow(Carbon::create(2026, 11, 12, 16, 0, 0, $tz));
        $day3 = $activityService->recordHeartbeat($student, 20, $tz, true);
        $this->assertSame(3, $day3['streakDays']);
        $this->assertSame(3, $day3['longestStreakDays']);
        $this->assertSame(3, (int) $participant->fresh()->streak_days);

        // Day 4 (Nov 13): student has NOT opened yet today, still within grace until midnight -> streak still 3
        Carbon::setTestNow(Carbon::create(2026, 11, 13, 15, 0, 0, $tz));
        $day4Snap = $activityService->getActivitySnapshot($student, $tz);
        $this->assertSame(3, $day4Snap['streakDays']);
        $this->assertFalse($day4Snap['isActiveToday']);

        // Day 5 (Nov 14): student MISSED all of Day 4 (Nov 13) -> streak is IMMEDIATELY GONE (0) everywhere!
        Carbon::setTestNow(Carbon::create(2026, 11, 14, 10, 0, 0, $tz));

        // 1) Parent portal immediately sees 0 (NOT stale 3 and NOT enrollment streak 9)
        $parentOverview = app(ParentPortalService::class)->getOverview($parent);
        $this->assertSame(0, $parentOverview['children'][0]['streakDays']);

        // 2) Student profile API immediately returns 0
        Sanctum::actingAs($student, ['access']);
        $this->getJson('/api/v1/student/profile')
            ->assertOk()
            ->assertJsonPath('data.currentStreakDays', 0)
            ->assertJsonPath('data.longestStreakDays', 3);

        // 3) League sync immediately resets league_participants.streak_days to 0
        app(LeagueRulesService::class)->syncCourseLeague($league);
        $this->assertSame(0, (int) $participant->fresh()->streak_days);

        // 4) Daily expiry command zeros stale DB profile rows cleanly
        $expiredCount = $activityService->expireStaleStreaks();
        $this->assertSame(1, $expiredCount);
        $this->assertSame(0, (int) StudentProfile::where('student_id', $student->id)->value('current_streak_days'));

        // 5) Student finally opens the platform on Day 5 (Nov 14) -> starts fresh from Day 1 with reset flag!
        $returnDay = $activityService->recordHeartbeat($student, 10, $tz, true);
        $this->assertSame(1, $returnDay['streakDays']);
        $this->assertTrue($returnDay['streakWasReset']);
        $this->assertSame(3, $returnDay['previousStreakDays']);
        $this->assertSame(3, $returnDay['longestStreakDays']);
        $this->assertSame(1, (int) $participant->fresh()->streak_days);

        // Subsequent dashboard call on the SAME reset day still reports streak=1 and streakWasReset=true
        $this->getJson('/api/v1/student/dashboard?timezone=Africa/Cairo')
            ->assertOk()
            ->assertJsonPath('data.kpis.streakDays', 1)
            ->assertJsonPath('data.kpis.streakWasReset', true)
            ->assertJsonPath('data.kpis.previousStreakDays', 3)
            ->assertJsonPath('data.kpis.longestStreakDays', 3);

        // Day 6 (Nov 15): consecutive after restart -> climbs to Day 2, streakWasReset becomes false
        Carbon::setTestNow(Carbon::create(2026, 11, 15, 11, 0, 0, $tz));
        $day6 = $activityService->recordHeartbeat($student, 15, $tz, true);
        $this->assertSame(2, $day6['streakDays']);
        $this->assertFalse($day6['streakWasReset']);
        $this->assertSame(3, $day6['longestStreakDays']);
    }

    public function test_expire_streaks_artisan_command_resets_only_absent_students(): void
    {
        $tz = 'Africa/Cairo';
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 9, 0, 0, $tz));

        $activeStudent = $this->createVerifiedUser(UserRole::STUDENT);
        $absentStudent = $this->createVerifiedUser(UserRole::STUDENT);

        StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'student_id' => $activeStudent->id,
            'current_streak_days' => 5,
            'longest_streak_days' => 5,
            'last_active_date' => '2026-11-19', // yesterday -> still alive!
            'timezone' => $tz,
        ]);

        StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'student_id' => $absentStudent->id,
            'current_streak_days' => 6,
            'longest_streak_days' => 6,
            'last_active_date' => '2026-11-18', // missed Nov 19 -> broken!
            'timezone' => $tz,
        ]);

        StudentDailyActivity::create([
            'student_id' => $absentStudent->id,
            'activity_date' => '2026-11-18',
            'active_seconds' => 300,
            'visits_count' => 1,
        ]);

        $this->artisan('students:expire-streaks')->assertSuccessful();

        $this->assertSame(5, (int) StudentProfile::where('student_id', $activeStudent->id)->value('current_streak_days'));
        $this->assertSame(0, (int) StudentProfile::where('student_id', $absentStudent->id)->value('current_streak_days'));
        $this->assertSame(6, (int) StudentProfile::where('student_id', $absentStudent->id)->value('longest_streak_days'));
    }
}
