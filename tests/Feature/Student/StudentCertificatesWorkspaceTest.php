<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\CertificateStatus;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LeagueDuration;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\Exam;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\LeagueParticipant;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCertificatesWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function createTeacher(string $name = 'د. أحمد الشافعي', string $email = 'teacher.cert.ws@motafawweq.com'): User
    {
        $teacher = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id' => $teacher->id,
            'name_ar' => $name,
            'name_en' => 'Dr. Ahmed El-Shafei',
            'title_ar' => 'خبير الفيزياء للثانوية العامة',
            'title_en' => 'Senior Physics Lecturer',
            'subject_ar' => 'الفيزياء',
        ]);

        return $teacher;
    }

    private function createStudent(string $name = 'يوسف طارق', string $email = 'student.cert.ws@motafawweq.com'): User
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

    public function test_workspace_returns_verified_certificates_with_real_teacher_data_and_strict_eligibility(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء الكهربائية',
            'title_en' => 'Electrical Physics',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
            'price_egp' => 250,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة النخبة',
            'center_name' => 'أونلاين',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'schedule_ar' => 'الأحد والأربعاء',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now()->subDays(10),
        ]);

        // 1. Fresh league with rank = 1 but xp_points = 0 -> must NOT grant league eligibility!
        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'study_group_id' => $group->id,
            'title_ar' => 'دوري الفيزياء',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(29),
        ]);
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 0,
            'streak_days' => 0,
        ]);

        // 2. Ungraded exam with percentage = 95 -> must NOT grant ministry eligibility!
        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار الفصل الأول',
            'status' => ExamStatus::ACTIVE,
            'duration_minutes' => 45,
            'full_mark' => 20,
            'pass_mark' => 10,
        ]);
        ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(10),
            'score' => 19,
            'full_mark' => 20,
            'percentage' => 95,
            'is_passed' => true,
            'status' => ExamSubmissionStatus::SUBMITTED,
        ]);

        // 3. Verified teacher-issued certificate matched by student_email (student_id = null)
        $emailCert = CourseCertificate::create([
            'teacher_id' => $teacher->id,
            'student_id' => null,
            'student_name_ar' => $student->name,
            'student_email' => strtoupper($student->email),
            'course_name_ar' => 'ورشة المراجعة النهائية',
            'grade_ar' => 'الصف الثالث الثانوي',
            'grade_percent' => 97.5,
            'honors_title_ar' => 'شهادة تفوق متميزة',
            'issue_date' => '2026-11-15',
            'status' => CertificateStatus::VERIFIED,
        ]);

        // 4. Revoked certificate -> must NOT appear in student's verified certificates list
        CourseCertificate::create([
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'student_name_ar' => $student->name,
            'course_name_ar' => 'شهادة ملغاة',
            'grade_percent' => 88,
            'honors_title_ar' => 'ملغاة',
            'issue_date' => '2026-11-10',
            'status' => CertificateStatus::REVOKED,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/certificates/workspace');
        $res->assertOk();

        $certs = $res->json('data.certificates');
        $this->assertCount(1, $certs);
        $this->assertSame($emailCert->certificate_number, $certs[0]['serialId']);
        $this->assertSame('teacher', $certs[0]['issuerType']);
        $this->assertSame('د. أحمد الشافعي', $certs[0]['instructorAr']);
        $this->assertSame('Dr. Ahmed El-Shafei', $certs[0]['instructorEn']);
        $this->assertSame('ورشة المراجعة النهائية', $certs[0]['courseNameAr']);
        $this->assertSame('97.5%', $certs[0]['score']);
        $this->assertSame('2026-11-15', $certs[0]['completionDate']);
        $this->assertSame("/verify/{$emailCert->certificate_number}", $certs[0]['verifyPath']);

        // Strict eligibility: all 3 false until genuine graded/points/completed criteria met!
        $elig = $res->json('data.eligibility');
        $this->assertFalse($elig['teacher']['eligible']);
        $this->assertFalse($elig['ministry']['eligible']);
        $this->assertFalse($elig['league']['eligible']);
    }

    public function test_student_can_claim_verified_course_certificate_only_after_100_percent_completion(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('منة الله كريم', 'menna.cert@motafawweq.com');
        $otherTeacher = $this->createTeacher('أ. علي حسن', 'other.t.cert@motafawweq.com');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الكيمياء العضوية المتقدمة',
            'title_en' => 'Advanced Organic Chemistry',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
            'price_egp' => 300,
        ]);

        $unsubscribedCourse = Course::create([
            'teacher_id' => $otherTeacher->id,
            'title_ar' => 'كورس غير مشترك به',
            'subject_ar' => 'أحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
            'price_egp' => 300,
        ]);

        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الكيمياء',
            'center_name' => 'أونلاين',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'schedule_ar' => 'الإثنين والخميس',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now()->subDays(5),
        ]);

        $lesson1 = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدرس الأول',
            'sort_order' => 1,
            'status' => LessonStatus::PUBLISHED,
        ]);
        $lesson2 = Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدرس الثاني',
            'sort_order' => 2,
            'status' => LessonStatus::PUBLISHED,
        ]);

        // Only 1 of 2 lessons completed -> claim must fail with 422
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson1->id,
            'is_completed' => true,
            'watched_seconds' => 600,
            'completed_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Cross-course IDOR blocked with 403
        $this->postJson('/api/v1/student/certificates/claim', ['course_id' => $unsubscribedCourse->uuid])
            ->assertForbidden();

        // 2. Incomplete course rejected with 422
        $this->postJson('/api/v1/student/certificates/claim', ['course_id' => $course->uuid])
            ->assertUnprocessable();

        // 3. Complete lesson 2 + add graded exam (94%) -> course becomes claimable!
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson2->id,
            'is_completed' => true,
            'watched_seconds' => 700,
            'completed_at' => now(),
        ]);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'امتحان شامل',
            'status' => ExamStatus::ACTIVE,
            'duration_minutes' => 30,
            'full_mark' => 50,
            'pass_mark' => 25,
        ]);
        ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'started_at' => now()->subMinutes(40),
            'submitted_at' => now()->subMinutes(10),
            'score' => 47,
            'full_mark' => 50,
            'percentage' => 94.0,
            'is_passed' => true,
            'status' => ExamSubmissionStatus::GRADED,
        ]);

        $wsBefore = $this->getJson('/api/v1/student/certificates/workspace');
        $wsBefore->assertOk();
        $this->assertTrue($wsBefore->json('data.eligibility.teacher.eligible'));
        $this->assertTrue($wsBefore->json('data.eligibility.ministry.eligible'));
        $this->assertCount(1, $wsBefore->json('data.eligibility.teacher.claimableCourses'));
        $this->assertSame($course->uuid, $wsBefore->json('data.eligibility.teacher.claimableCourses.0.courseId'));

        // 4. Claim certificate -> creates real cryptographically signed CourseCertificate
        $claimRes = $this->postJson('/api/v1/student/certificates/claim', ['course_id' => $course->uuid]);
        $claimRes->assertCreated()
            ->assertJsonPath('data.studentNameAr', 'منة الله كريم')
            ->assertJsonPath('data.courseNameAr', 'الكيمياء العضوية المتقدمة')
            ->assertJsonPath('data.instructorAr', 'د. أحمد الشافعي')
            ->assertJsonPath('data.score', '94%')
            ->assertJsonPath('data.completionRate', '100%');

        $serial = $claimRes->json('data.serialId');
        $this->assertNotEmpty($serial);

        // 5. Public verification endpoint immediately validates the claimed certificate's HMAC signature
        $this->getJson("/api/v1/verify-certificate/{$serial}")
            ->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.studentNameAr', 'منة الله كريم')
            ->assertJsonPath('data.courseNameAr', 'الكيمياء العضوية المتقدمة');

        // 6. Claiming again stays idempotent (no duplicate row) and claimableCourses is now empty
        $this->postJson('/api/v1/student/certificates/claim', ['course_id' => $course->uuid])
            ->assertCreated()
            ->assertJsonPath('data.serialId', $serial);

        $this->assertSame(1, CourseCertificate::where('student_id', $student->id)->where('course_id', $course->id)->count());

        $wsAfter = $this->getJson('/api/v1/student/certificates/workspace');
        $wsAfter->assertOk();
        $this->assertCount(0, $wsAfter->json('data.eligibility.teacher.claimableCourses'));
        $this->assertCount(1, $wsAfter->json('data.certificates'));

        // 7. Track 02 (ministry): Claim verified exam excellence certificate for the 94% graded exam!
        $this->assertCount(1, $wsAfter->json('data.eligibility.ministry.claimableExams'));
        $examClaim = $this->postJson('/api/v1/student/certificates/claim', [
            'track' => 'ministry',
            'target_id' => $exam->uuid,
        ]);
        $examClaim->assertCreated()
            ->assertJsonPath('data.issuerType', 'ministry')
            ->assertJsonPath('data.courseNameAr', 'امتحان شامل')
            ->assertJsonPath('data.score', '94%');

        $examSerial = $examClaim->json('data.serialId');
        $this->getJson("/api/v1/verify-certificate/{$examSerial}")
            ->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.issuerType', 'ministry')
            ->assertJsonPath('data.score', '94%');

        // 8. Track 03 (league): Active course league where student has rank #1 and 45 points -> claimable!
        $league = CourseLeague::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'study_group_id' => $group->id,
            'title_ar' => 'دوري الكيمياء الشهري',
            'course_name_ar' => $course->title_ar,
            'duration_type' => LeagueDuration::MONTHLY,
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
        ]);
        LeagueParticipant::create([
            'course_league_id' => $league->id,
            'student_id' => $student->id,
            'rank' => 1,
            'xp_points' => 45,
            'streak_days' => 3,
        ]);

        $wsLeague = $this->getJson('/api/v1/student/certificates/workspace');
        $wsLeague->assertOk();
        $this->assertTrue($wsLeague->json('data.eligibility.league.eligible'));
        $this->assertCount(1, $wsLeague->json('data.eligibility.league.claimableLeagues'));

        $leagueClaim = $this->postJson('/api/v1/student/certificates/claim', [
            'track' => 'league',
            'target_id' => $league->uuid,
        ]);
        $leagueClaim->assertCreated()
            ->assertJsonPath('data.issuerType', 'league')
            ->assertJsonPath('data.rankAr', 'المركز الأول 🥇')
            ->assertJsonPath('data.pointsAr', '45 نقطة');

        $leagueSerial = $leagueClaim->json('data.serialId');
        $this->getJson("/api/v1/verify-certificate/{$leagueSerial}")
            ->assertOk()
            ->assertJsonPath('data.isValid', true)
            ->assertJsonPath('data.issuerType', 'league')
            ->assertJsonPath('data.rankAr', 'المركز الأول 🥇');
    }

    public function test_certificates_workspace_blocks_guests_and_non_students(): void
    {
        $this->getJson('/api/v1/student/certificates/workspace')->assertUnauthorized();

        $teacher = $this->createTeacher('أ. كريم', 'teacher.block.cert@motafawweq.com');
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/student/certificates/workspace')->assertForbidden();
    }
}
