<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\DiscountCoupon;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\Homework;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\StudentXpLedger;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class StudentSecurityAndPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.sec@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'د. محمود المتفوق',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $email = 'student.sec@motafawweq.com', string $name = 'أحمد الطالب'): User
    {
        $student = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(10000, 99999),
        ]);

        StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PRO,
            'plan_name_ar' => StudentPlanTier::PRO->labelAr(),
            'plan_name_en' => StudentPlanTier::PRO->labelEn(),
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => 80,
            'starts_at' => now(),
            'renews_at' => now()->addDays(30),
            'status' => 'active',
        ]);

        return $student;
    }

    protected function enrollStudentWithTeacher(User $student, User $teacher, ?Course $course = null): StudyGroup
    {
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course?->id,
            'name_ar' => 'مجموعة النخبة بالسنتر',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الرواد — الدقي',
            'hall_name' => 'قاعة 1',
            'price_egp' => 500,
            'join_code' => 'ELITE-'.$teacher->id.'-'.random_int(100, 999),
            'status' => 'active',
        ]);

        GroupScheduleSlot::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::SUNDAY->value,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'hall_name' => 'قاعة 1',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'student_barcode' => "STD-{$group->id}-{$student->id}-ABC",
            'enrolled_at' => now(),
        ]);

        return $group;
    }

    public function test_phase0_and_phase1_non_student_roles_are_blocked_from_student_endpoints(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/student/dashboard')->assertForbidden();
        $this->getJson('/api/v1/student/profile')->assertForbidden();
        $this->postJson('/api/v1/groups/join', ['join_code' => 'ANY-CODE'])->assertForbidden();
    }

    public function test_phase1_xp_ledger_is_append_only_and_immutable(): void
    {
        $student = $this->createStudent();

        $entry = StudentXpLedger::awardOnce($student->id, 'lesson_completed', 101, 25, 'إتمام درس 1');
        $this->assertNotNull($entry);

        // Awarding again for same source is idempotent (no duplicate points)
        $duplicate = StudentXpLedger::awardOnce($student->id, 'lesson_completed', 101, 25, 'إتمام درس 1');
        $this->assertEquals($entry->id, $duplicate->id);
        $this->assertEquals(25, StudentXpLedger::forStudent($student->id)->sum('points'));

        $this->expectException(LogicException::class);
        $entry->update(['points' => 9999]);
    }

    public function test_phase1_unenrolled_student_idor_on_exam_and_homework_is_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.sec@motafawweq.com');
        $unenrolledStudent = $this->createStudent('unenrolled@motafawweq.com');

        $exam = Exam::create([
            'teacher_id' => $teacherA->id,
            'title_ar' => 'امتحان سري',
            'duration_minutes' => 30,
            'full_mark' => 20,
            'pass_score' => 10,
            'status' => ExamStatus::ACTIVE,
        ]);

        $hw = Homework::create([
            'teacher_id' => $teacherA->id,
            'title_ar' => 'واجب خاص',
            'max_grade' => 20,
        ]);

        Sanctum::actingAs($unenrolledStudent, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/student/exams/{$exam->uuid}/start")->assertForbidden();
        $this->postJson("/api/v1/student/exams/{$exam->uuid}/submit", ['answers' => []])->assertForbidden();
        $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", ['solution_text' => 'حل'])->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $unenrolledStudent->id,
            'event' => 'student.idor_attempt_blocked',
            'level' => 'warning',
        ]);
    }

    public function test_phase2_student_profile_and_privacy_controls(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $this->putJson('/api/v1/student/profile', [
            'name' => '<b>أحمد</b> السعيد',
            'governorate' => 'الجيزة',
            'school_name' => 'مدرسة الأورمان',
            'daily_study_goal_minutes' => 180,
            'show_on_leaderboard' => false,
            'display_name_mode' => 'initials',
        ])->assertOk()
            ->assertJsonPath('data.name', 'أحمد السعيد')
            ->assertJsonPath('data.dailyStudyGoalMinutes', 180)
            ->assertJsonPath('data.showOnLeaderboard', false)
            ->assertJsonPath('data.displayNameMode', 'initials');
    }

    public function test_phase3_student_study_sessions_enforce_overlap_against_group_classes_and_other_sessions(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $otherStudent = $this->createStudent('other.std@motafawweq.com');

        // Student has a group class on Sunday 16:00 - 18:00
        $this->enrollStudentWithTeacher($student, $teacher);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Overlapping with Sunday 16:00 - 18:00 group class -> 422
        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'sunday',
            'subject' => 'مراجعة أحياء',
            'title' => 'فصل التكاثر',
            'startTime' => '17:00',
            'endTime' => '19:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // 2. Non-overlapping slot on Sunday 19:00 - 21:00 -> 201 Created
        $res = $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'sunday',
            'subject' => 'مراجعة أحياء',
            'title' => 'فصل التكاثر',
            'startTime' => '19:00',
            'endTime' => '21:00',
        ]);
        $res->assertCreated();
        $sessionUuid = $res->json('data.id');

        // 3. Overlapping with own personal study session on Sunday 20:00 - 22:00 -> 422
        $this->postJson('/api/v1/student/study-sessions', [
            'day' => 'sunday',
            'subject' => 'فيزياء',
            'title' => 'الكهربية',
            'startTime' => '20:00',
            'endTime' => '22:00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // 4. Cross-student IDOR: otherStudent cannot delete student's study session -> 403
        Sanctum::actingAs($otherStudent, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/study-sessions/{$sessionUuid}")
            ->assertForbidden();
    }

    public function test_phase4_courses_lessons_anti_skip_heartbeat_and_notes_idor(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $otherStudent = $this->createStudent('intruder.notes@motafawweq.com');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الأحياء الشامل',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'الدرس الأول: الدعامة في الكائنات الحية',
            'duration_label' => '30:00',
            'status' => LessonStatus::PUBLISHED,
            'sort_order' => 1,
        ]);

        $this->enrollStudentWithTeacher($student, $teacher, $course);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $this->getJson("/api/v1/student/lessons/{$lesson->uuid}")
            ->assertOk()
            ->assertJsonPath('data.titleAr', 'الدرس الأول: الدعامة في الكائنات الحية')
            ->assertJsonPath('data.watermark.studentCode', $student->student_code);

        // Progress heartbeat
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/progress", [
            'position_seconds' => 20,
            'watched_delta_seconds' => 20,
            'duration_seconds' => 1800,
        ])->assertOk()
            ->assertJsonPath('data.watchedSeconds', 20);

        // Create private note
        $noteRes = $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/notes", [
            'timestamp_seconds' => 120,
            'content' => 'ملاحظة هامة عن الفقرات العنقية',
        ])->assertCreated();

        $noteUuid = $noteRes->json('data.id');

        // Intruder cannot delete student's note
        Sanctum::actingAs($otherStudent, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/lessons/notes/{$noteUuid}")
            ->assertForbidden();
    }

    public function test_phase5_and_phase6_server_timed_exam_attempt_and_homework_anti_spoofing(): void
    {
        Storage::fake('local');
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $otherStudent = $this->createStudent('other.hw@motafawweq.com');

        $this->enrollStudentWithTeacher($student, $teacher);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'امتحان الفصل الأول',
            'duration_minutes' => 20,
            'full_mark' => 10,
            'pass_score' => 5,
            'questions_count' => 1,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);

        $q = ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'كم عدد عظام الجمجمة وملحقاتها؟',
            'options_ar' => ['22', '29', '33'],
            'correct_index' => 1,
            'explanation_ar' => 'الجمجمة وملحقاتها 29 عظمة.',
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Start exam: must NOT leak correct_index or explanation_ar
        $startRes = $this->postJson("/api/v1/student/exams/{$exam->uuid}/start")
            ->assertOk();

        $this->assertArrayNotHasKey('correct_index', $startRes->json('data.questions.0'));
        $this->assertArrayNotHasKey('correctIndex', $startRes->json('data.questions.0'));

        // Submit wrong answer with 6 tab switches (triggers anti-cheat risk signal + populates mistake bank)
        $this->postJson("/api/v1/student/exams/{$exam->uuid}/submit", [
            'tabSwitchesCount' => 6,
            'answers' => [
                ['questionUuid' => $q->uuid, 'selectedIndex' => 0],
            ],
        ], ['Idempotency-Key' => 'exam-sub-key-001'])
            ->assertCreated()
            ->assertJsonPath('data.score', 0);

        // Replaying with the same Idempotency-Key returns the cached 201 response
        $this->postJson("/api/v1/student/exams/{$exam->uuid}/submit", [
            'answers' => [['questionUuid' => $q->uuid, 'selectedIndex' => 0]],
        ], ['Idempotency-Key' => 'exam-sub-key-001'])
            ->assertCreated()
            ->assertHeader('X-Idempotent-Replayed', 'true');

        // Anti-cheat risk signal logged
        $this->assertDatabaseHas('student_risk_signals', [
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
        ]);

        // Mistake bank contains the wrong question
        $this->getJson('/api/v1/student/mistake-bank')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.correctIndex', 1);

        // Homework submission ignores spoofed client location_ar and uses verified group center
        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'واجب الدعامة',
            'max_grade' => 20,
        ]);

        $hwRes = $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'الإجابة التفصيلية للواجب',
            'location_ar' => 'سنتر مزيف غير مشترك به',
        ])->assertCreated();

        $this->assertDatabaseHas('homework_submissions', [
            'homework_id' => $hw->id,
            'student_id' => $student->id,
            'location_ar' => 'سنتر الرواد — الدقي',
        ]);
    }

    public function test_phase7_to_phase11_leaderboard_privacy_billing_dashboard_and_route_audit(): void
    {
        $teacher = $this->createTeacher();
        $studentA = $this->createStudent('std.a@motafawweq.com', 'كريم محمود كامل');
        $studentB = $this->createStudent('std.b@motafawweq.com', 'سارة أحمد فؤاد');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الفيزياء الحديثة',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $group = $this->enrollStudentWithTeacher($studentA, $teacher, $course);

        // Student B opts for initials privacy mode
        StudentProfile::create([
            'student_id' => $studentB->id,
            'show_on_leaderboard' => true,
            'display_name_mode' => 'initials',
        ]);

        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'دوري أبطال الفيزياء',
            'course_title_ar' => $course->title_ar,
        ]);

        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $studentB->id,
            'student_name_ar' => $studentB->name,
            'xp_points' => 500,
        ]);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // Leaderboard masks Student B's family name to initials ("سارة أ.")
        $this->getJson("/api/v1/student/leagues/{$league->uuid}/leaderboard")
            ->assertOk()
            ->assertJsonPath('data.leaderboard.0.displayName', 'سارة أ.');

        // Server-priced Direct Payment with Atomic Coupon Redemption
        DiscountCoupon::create([
            'code' => 'SAVE20',
            'discount_percent' => 20,
            'min_order_egp' => 100,
            'is_active' => true,
            'max_uses' => 5,
        ]);

        // Group price is 500 EGP; 20% discount -> 400 EGP (client cannot tamper with amount)
        $this->postJson('/api/v1/student/billing/payments', [
            'group_uuid' => $group->uuid,
            'payment_method' => 'vodafone',
            'coupon_code' => 'SAVE20',
        ])->assertCreated()
            ->assertJsonPath('data.amountEgp', 400);

        // Dashboard & Analytics & Revision Hub
        $this->getJson('/api/v1/student/dashboard')->assertOk();
        $this->getJson('/api/v1/student/analytics')->assertOk();
        $this->getJson('/api/v1/student/revision')->assertOk();

        // Phase 11 Architecture Audit: Every route under api/v1/student/* MUST have role:student middleware
        $studentRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/student'));

        $this->assertGreaterThanOrEqual(20, $studentRoutes->count());

        foreach ($studentRoutes as $route) {
            $middlewares = $route->gatherMiddleware();
            $this->assertContains(
                'role:student',
                $middlewares,
                "Route [{$route->uri()}] is missing mandatory role:student middleware!"
            );
            $this->assertContains(
                'auth:sanctum',
                $middlewares,
                "Route [{$route->uri()}] is missing mandatory auth:sanctum middleware!"
            );
        }
    }
}
