<?php

namespace Tests\Feature\Parent;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\DayOfWeek;
use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Enums\InvoiceStatus;
use App\Enums\LessonStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\GroupScheduleSlot;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\SecurityAuditLog;
use App\Models\StudentDirectPayment;
use App\Models\StudentInvoice;
use App\Models\StudentLessonProgress;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedUser(UserRole $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_unauthenticated_and_non_parent_roles_are_strictly_blocked(): void
    {
        $this->getJson('/api/v1/parent/overview')->assertStatus(401);

        foreach ([UserRole::STUDENT, UserRole::TEACHER, UserRole::CENTER, UserRole::ADMIN] as $role) {
            $user = $this->createVerifiedUser($role);
            Sanctum::actingAs($user, ['access']);

            $this->getJson('/api/v1/parent/overview')->assertStatus(403);
            $this->postJson('/api/v1/parent/children/link', ['student_code' => 'STD-123456'])
                ->assertStatus(403);
            $this->getJson('/api/v1/parent/settings')->assertStatus(403);
        }

        // Refresh token rejected on access routes
        $parent = $this->createVerifiedUser(UserRole::PARENT);
        Sanctum::actingAs($parent, ['refresh']);
        $this->getJson('/api/v1/parent/overview')->assertStatus(401);
    }

    public function test_parent_cannot_access_unlinked_child_and_idor_attempt_is_audited(): void
    {
        $parentA = $this->createVerifiedUser(UserRole::PARENT);
        $parentB = $this->createVerifiedUser(UserRole::PARENT);
        $childOfB = $this->createVerifiedUser(UserRole::STUDENT, ['student_code' => 'STD-BBB111']);
        $parentB->children()->attach($childOfB->id);

        Sanctum::actingAs($parentA, ['access']);

        $this->getJson("/api/v1/parent/children/{$childOfB->id}/dashboard")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/courses")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/attendance")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/exams")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/exams/export")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/homework")->assertStatus(403);
        $this->getJson("/api/v1/parent/children/{$childOfB->id}/financials")->assertStatus(403);
        $this->deleteJson("/api/v1/parent/children/{$childOfB->id}")->assertStatus(403);
        $this->postJson('/api/v1/parent/inquiries', [
            'student_id' => $childOfB->id,
            'recipient_type' => 'center',
            'subject' => 'IDOR test',
            'message' => 'Trying to inquire about another parent child.',
        ], ['Idempotency-Key' => 'par-idor-1'])->assertStatus(403);

        $this->assertTrue(
            SecurityAuditLog::query()
                ->where('event', 'parent.idor_attempt_blocked')
                ->where('user_id', $parentA->id)
                ->exists()
        );
    }

    public function test_parent_can_link_and_unlink_children_by_student_code_and_center_barcode(): void
    {
        $parent = $this->createVerifiedUser(UserRole::PARENT);
        $teacher = $this->createVerifiedUser(UserRole::TEACHER);
        $studentA = $this->createVerifiedUser(UserRole::STUDENT, ['name' => 'عمر طارق', 'student_code' => 'STD-OMAR99']);
        $studentB = $this->createVerifiedUser(UserRole::STUDENT, ['name' => 'سارة طارق', 'student_code' => 'STD-SARA88']);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة العلوم 1 إعدادي',
            'subject_ar' => 'العلوم',
            'grade_ar' => 'الصف الأول الإعدادي',
            'center_name' => 'سنتر النخبة',
            'status' => 'active',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $studentB->id,
            'status' => EnrollmentStatus::ENROLLED,
            'student_barcode' => 'STD-001-0002-ABCDEF',
        ]);

        Sanctum::actingAs($parent, ['access']);

        // Invalid code -> 422
        $this->postJson('/api/v1/parent/children/link', ['student_code' => 'STD-DOESNOTEXIST'], ['Idempotency-Key' => 'link-bad'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_code']);

        // Link student A by student_code (case-insensitive)
        $this->postJson('/api/v1/parent/children/link', ['student_code' => 'std-omar99'], ['Idempotency-Key' => 'link-a'])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $studentA->id)
            ->assertJsonPath('data.studentCode', 'STD-OMAR99');

        // Duplicate link -> 422
        $this->postJson('/api/v1/parent/children/link', ['student_code' => 'STD-OMAR99'], ['Idempotency-Key' => 'link-a-dup'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_code']);

        // Link student B by center barcode
        $this->postJson('/api/v1/parent/children/link', ['student_code' => 'STD-001-0002-ABCDEF'], ['Idempotency-Key' => 'link-b'])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $studentB->id);

        // Overview returns both children
        $this->getJson('/api/v1/parent/overview')
            ->assertOk()
            ->assertJsonCount(2, 'data.children');

        // Unlink student B
        $this->deleteJson("/api/v1/parent/children/{$studentB->id}")->assertOk();

        $this->getJson('/api/v1/parent/overview')
            ->assertOk()
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.children.0.id', $studentA->id);
    }

    public function test_parent_dashboard_returns_accurate_live_courses_attendance_exams_homework_and_financials(): void
    {
        $parent = $this->createVerifiedUser(UserRole::PARENT, ['name' => 'م. طارق القاضي', 'phone' => '01223344556']);
        $teacher = $this->createVerifiedUser(UserRole::TEACHER, ['name' => 'د. سلمى السيد']);
        $center = $this->createVerifiedUser(UserRole::CENTER, ['name' => 'سنتر الدقي النخبة', 'phone' => '01003344220']);
        $student = $this->createVerifiedUser(UserRole::STUDENT, ['name' => 'عمر طارق القاضي', 'student_code' => 'STD-2026OM']);
        $peerStudent = $this->createVerifiedUser(UserRole::STUDENT, ['name' => 'زميل بالصف', 'student_code' => 'STD-PEER01']);

        CenterProfile::create([
            'user_id' => $center->id,
            'name_ar' => 'سنتر الدقي النخبة',
            'name_en' => 'Dokki Elite Center',
            'phone' => '01003344220',
        ]);

        StudentProfile::create([
            'uuid' => (string) Str::uuid(),
            'student_id' => $student->id,
            'grade_ar' => 'الصف الثالث الثانوي',
            'track_ar' => 'علمي علوم',
            'school_name' => 'مدرسة السعيدية الثانوية',
            'center_name' => 'سنتر الدقي النخبة',
            'current_streak_days' => 14,
        ]);

        $parent->children()->attach($student->id);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الأحياء للثانوية العامة',
            'title_en' => 'Biology 3rd Secondary',
            'subject_ar' => 'الأحياء',
            'subject_en' => 'Biology',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 350,
            'lessons_count' => 2,
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson1 = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدعامة والحركة في الكائنات الحية',
            'status' => LessonStatus::PUBLISHED,
        ]);
        Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'التنسيق الهرموني',
            'status' => LessonStatus::PUBLISHED,
        ]);

        StudentLessonProgress::create([
            'uuid' => (string) Str::uuid(),
            'student_id' => $student->id,
            'lesson_id' => $lesson1->id,
            'course_id' => $course->id,
            'progress_percent' => 100,
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'center_id' => $center->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الأحياء - الدقي',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الدقي النخبة',
            'hall_name' => 'قاعة 1 - الدور الثاني',
            'schedule_ar' => 'السبت والثلاثاء 04:00 م',
            'status' => 'active',
        ]);

        GroupScheduleSlot::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => DayOfWeek::SATURDAY,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'hall_name' => 'قاعة 1 - الدور الثاني',
        ]);

        $enrollment = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 100,
            'attended_sessions' => 1,
            'total_sessions' => 1,
            'avg_quiz_score' => 92,
            'due_amount_egp' => 150,
            'payment_status' => 'overdue',
        ]);

        // Attendance session + record
        $attSession = GroupAttendanceSession::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'center_id' => $center->id,
            'session_date' => now()->toDateString(),
            'title_ar' => 'حصة المراجعة',
            'status' => 'completed',
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $attSession->id,
            'study_group_id' => $group->id,
            'group_enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => AttendanceStatus::PRESENT,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => now(),
            'recorded_by' => $center->id,
        ]);

        // Exam + graded submissions (peer got 40/50, child got 46/50 -> Rank #1 of 2)
        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار الدعامة والحركة',
            'course_title_ar' => 'الأحياء',
            'full_mark' => 50,
            'pass_score' => 25,
            'status' => ExamStatus::ACTIVE,
        ]);

        ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $peerStudent->id,
            'score' => 40,
            'full_mark' => 50,
            'percentage' => 80,
            'is_passed' => true,
            'status' => ExamSubmissionStatus::GRADED,
            'submitted_at' => now()->subHours(2),
        ]);

        ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'score' => 46,
            'full_mark' => 50,
            'percentage' => 92,
            'is_passed' => true,
            'status' => ExamSubmissionStatus::GRADED,
            'teacher_feedback' => '=SUM(A1:A2) ممتاز جداً يا عمر',
            'submitted_at' => now()->subHour(),
            'graded_at' => now(),
        ]);

        // Homework + graded submission
        $homework = Homework::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'course_title_ar' => 'الأحياء',
            'title_ar' => 'واجب الفصل الأول',
            'type' => HomeworkType::TEXT,
            'max_grade' => 20,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        HomeworkSubmission::create([
            'homework_id' => $homework->id,
            'student_id' => $student->id,
            'status' => HomeworkSubmissionStatus::GRADED,
            'score' => 19,
            'max_score' => 20,
            'feedback_ar' => 'حل منظم ودقيق.',
            'submitted_at' => now()->subHours(3),
            'graded_at' => now(),
        ]);

        // Financials: subscription invoice + direct payment + due from enrollment
        $sub = StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PLUS,
            'plan_name_ar' => 'باقة الطالب بلس',
            'plan_name_en' => 'Student Plus',
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => 99,
            'starts_at' => now(),
            'renews_at' => now()->addDays(30),
            'status' => 'active',
        ]);

        StudentInvoice::create([
            'student_id' => $student->id,
            'subscription_id' => $sub->id,
            'description_ar' => 'اشتراك باقة بلس الشهرية',
            'base_amount_egp' => 99,
            'discount_amount_egp' => 0,
            'final_amount_egp' => 99,
            'payment_method' => PaymentMethod::INSTAPAY,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now()->subDays(2),
        ]);

        StudentDirectPayment::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'student_name_ar' => $student->name,
            'course_id' => $course->id,
            'study_group_id' => $group->id,
            'amount_egp' => 350,
            'payment_method' => PaymentMethod::VODAFONE,
            'course_name_ar' => 'اشتراك الأحياء - نوفمبر',
            'status' => DirectPaymentStatus::CONFIRMED,
            'paid_at' => now()->subDay(),
            'confirmed_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($parent, ['access']);

        $res = $this->getJson("/api/v1/parent/children/{$student->id}/dashboard")->assertOk();

        $res->assertJsonPath('data.summary.id', $student->id)
            ->assertJsonPath('data.summary.attendanceRate', 100)
            ->assertJsonPath('data.summary.presentSessionsCount', 1)
            ->assertJsonPath('data.summary.totalSessionsCount', 1)
            ->assertJsonPath('data.summary.avgQuizScore', 92)
            ->assertJsonPath('data.summary.streakDays', 14)
            ->assertJsonPath('data.summary.centerPhone', '01003344220')
            ->assertJsonPath('data.courses.items.0.completedLessons', 1)
            ->assertJsonPath('data.courses.items.0.totalLessons', 2)
            ->assertJsonPath('data.courses.items.0.progress', 50)
            ->assertJsonPath('data.attendance.summary.presentCount', 1)
            ->assertJsonPath('data.exams.summary.avgPercent', 92)
            ->assertJsonPath('data.exams.items.0.rankAr', 'المركز 1 من 2')
            ->assertJsonPath('data.homework.summary.graded', 1)
            ->assertJsonPath('data.homework.items.0.earnedScore', 19)
            ->assertJsonPath('data.financials.summary.totalPaidEgp', 449)
            ->assertJsonPath('data.financials.summary.dueAmountEgp', 150)
            ->assertJsonCount(3, 'data.financials.invoices');

        // Single round-trip overview with include_child=1 embeds activeChildDashboard + inquiries
        $this->getJson('/api/v1/parent/overview?include_child=1')
            ->assertOk()
            ->assertJsonPath('data.activeChildId', $student->id)
            ->assertJsonPath('data.activeChildDashboard.summary.id', $student->id)
            ->assertJsonPath('data.activeChildDashboard.courses.items.0.progress', 50)
            ->assertJsonPath('data.activeChildDashboard.financials.summary.totalPaidEgp', 449);

        // CSV Export works & sanitizes leading '=' formula injection!
        $csvRes = $this->get("/api/v1/parent/children/{$student->id}/exams/export")->assertOk();
        $csvBody = $csvRes->getContent();
        $this->assertStringContainsString('اختبار الدعامة والحركة', $csvBody);
        $this->assertStringContainsString("'=SUM(A1:A2)", $csvBody);
    }

    public function test_parent_can_submit_inquiries_and_update_settings(): void
    {
        $parent = $this->createVerifiedUser(UserRole::PARENT, ['name' => 'م. طارق', 'phone' => '01200000001']);
        $student = $this->createVerifiedUser(UserRole::STUDENT, ['student_code' => 'STD-INQ001']);
        $parent->children()->attach($student->id);

        Sanctum::actingAs($parent, ['access']);

        // Create inquiry
        $this->postJson('/api/v1/parent/inquiries', [
            'student_id' => $student->id,
            'recipient_type' => 'both',
            'subject' => 'استفسار عن المراجعة النهائية',
            'message' => 'هل يوجد موعد مسائي لمجموعة الأحياء هذا الأسبوع؟',
        ], ['Idempotency-Key' => 'par-inq-1'])
            ->assertStatus(201)
            ->assertJsonPath('data.studentId', $student->id)
            ->assertJsonPath('data.status', 'open');

        // Reject unrelated teacher_id in inquiry with 422
        $otherTeacher = $this->createVerifiedUser(UserRole::TEACHER);
        $this->postJson('/api/v1/parent/inquiries', [
            'student_id' => $student->id,
            'teacher_id' => $otherTeacher->id,
            'recipient_type' => 'teacher',
            'subject' => 'معلم غير مرتبط',
            'message' => 'محاولة إرسال استفسار لمعلم لا يدرّس للطالب.',
        ], ['Idempotency-Key' => 'par-inq-bad-teacher'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['teacher_id']);

        // List inquiries
        $this->getJson('/api/v1/parent/inquiries')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        // Update settings & verify persistence
        $this->putJson('/api/v1/parent/settings', [
            'name' => 'م. طارق القاضي',
            'phone' => '01223344556',
            'emergency_phone' => '01001122334',
            'notify_tuition_renewal' => false,
            'notify_achievements_certificates' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'م. طارق القاضي')
            ->assertJsonPath('data.emergencyPhone', '01001122334')
            ->assertJsonPath('data.notifyTuitionRenewal', false)
            ->assertJsonPath('data.notifyAchievementsCertificates', true);

        $this->getJson('/api/v1/parent/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'م. طارق القاضي')
            ->assertJsonPath('data.emergencyPhone', '01001122334')
            ->assertJsonPath('data.notifyTuitionRenewal', false);
    }

    public function test_individual_tab_endpoints_filters_pagination_and_center_paid_financials(): void
    {
        $parent = $this->createVerifiedUser(UserRole::PARENT);
        $teacher = $this->createVerifiedUser(UserRole::TEACHER, ['name' => 'أ. خالد محمود']);
        $center = $this->createVerifiedUser(UserRole::CENTER, ['name' => 'سنتر النخبة']);
        $student = $this->createVerifiedUser(UserRole::STUDENT, ['name' => 'سارة طارق', 'student_code' => 'STD-TABS01']);
        $parent->children()->attach($student->id);

        // Standalone offline center group (paid at center reception without StudentDirectPayment)
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'center_id' => $center->id,
            'name_ar' => 'مجموعة الفيزياء - فرع الدقي',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة 3',
            'price_egp' => 400,
            'status' => 'active',
        ]);

        $enrollment = GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 50,
            'attended_sessions' => 1,
            'total_sessions' => 2,
            'payment_status' => 'paid',
            'due_amount_egp' => 0,
            'enrolled_at' => now()->subDays(5),
        ]);

        // 2 attendance records: 1 present, 1 absent
        $session1 = GroupAttendanceSession::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'center_id' => $center->id,
            'session_date' => now()->subDays(2)->toDateString(),
            'title_ar' => 'حصة 1',
            'status' => 'completed',
        ]);
        $session2 = GroupAttendanceSession::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'center_id' => $center->id,
            'session_date' => now()->subDay()->toDateString(),
            'title_ar' => 'حصة 2',
            'status' => 'completed',
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $session1->id,
            'study_group_id' => $group->id,
            'group_enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => AttendanceStatus::PRESENT,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => now()->subDays(2),
            'recorded_by' => $center->id,
        ]);
        GroupAttendanceRecord::create([
            'attendance_session_id' => $session2->id,
            'study_group_id' => $group->id,
            'group_enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => AttendanceStatus::ABSENT,
            'recorded_method' => AttendanceRecordMethod::MANUAL_TOGGLE,
            'scanned_at' => now()->subDay(),
            'recorded_by' => $center->id,
        ]);

        // 2 homeworks: 1 graded, 1 pending (not submitted)
        $hw1 = Homework::create([
            'teacher_id' => $teacher->id,
            'course_title_ar' => 'الفيزياء',
            'title_ar' => 'واجب التيار الكهربي',
            'type' => HomeworkType::TEXT,
            'max_grade' => 20,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);
        Homework::create([
            'teacher_id' => $teacher->id,
            'course_title_ar' => 'الفيزياء',
            'title_ar' => 'واجب قانون أوم',
            'type' => HomeworkType::TEXT,
            'max_grade' => 20,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        HomeworkSubmission::create([
            'homework_id' => $hw1->id,
            'student_id' => $student->id,
            'status' => HomeworkSubmissionStatus::GRADED,
            'score' => 18,
            'max_score' => 20,
            'submitted_at' => now()->subHour(),
            'graded_at' => now(),
        ]);

        // 1 submitted (ungraded) exam
        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كويز الفصل الأول',
            'course_title_ar' => 'الفيزياء',
            'full_mark' => 30,
            'pass_score' => 15,
            'status' => ExamStatus::ACTIVE,
        ]);
        ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'full_mark' => 30,
            'status' => ExamSubmissionStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($parent, ['access']);

        // Tab 2: Courses (includes standalone offline group!)
        $this->getJson("/api/v1/parent/children/{$student->id}/courses")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.source', 'group')
            ->assertJsonPath('data.items.0.progress', 50);

        // Tab 3: Attendance with server-side status filter
        $this->getJson("/api/v1/parent/children/{$student->id}/attendance?status=absent")
            ->assertOk()
            ->assertJsonPath('data.summary.presentCount', 1)
            ->assertJsonPath('data.summary.absentCount', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'absent');

        // Tab 4: Exams (pending submitted exam + pagination metadata)
        $this->getJson("/api/v1/parent/children/{$student->id}/exams?per_page=10")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'submitted')
            ->assertJsonPath('data.pagination.total', 1);

        // Tab 5: Homework with status filter + overdue tracking
        $this->getJson("/api/v1/parent/children/{$student->id}/homework?status=pending")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.graded', 1)
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.summary.overdue', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.isOverdue', true);

        // Tab 6: Financials reflects center-paid enrollment (400 EGP) without needing StudentDirectPayment
        $this->getJson("/api/v1/parent/children/{$student->id}/financials")
            ->assertOk()
            ->assertJsonPath('data.summary.totalPaidEgp', 400)
            ->assertJsonPath('data.summary.dueAmountEgp', 0)
            ->assertJsonPath('data.invoices.0.source', 'group_enrollment')
            ->assertJsonPath('data.invoices.0.status', 'paid');

        // Overview with include_child=1 also includes inquiriesPagination
        $this->getJson('/api/v1/parent/overview?include_child=1')
            ->assertOk()
            ->assertJsonPath('data.inquiriesPagination.currentPage', 1);
    }
}
