<?php

namespace Tests\Feature\Teacher;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\RiskLevel;
use App\Enums\RiskSignalStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\ExamSubmissionAnswer;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\StudentRiskSignal;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.analytics@motafawweq.com'): User
    {
        $teacher = User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id'      => $teacher->id,
            'name_ar'      => $teacher->name,
            'teacher_code' => 'TCH-9922',
            'headline_ar'  => 'خبير أول الأحياء',
            'subject_ar'   => 'الأحياء',
        ]);

        return $teacher;
    }

    protected function createStudent(string $name, string $email): User
    {
        return User::factory()->create([
            'name'              => $name,
            'email'             => $email,
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_teacher_can_fetch_analytics_overview_with_cohort_kpis(): void
    {
        $teacher = $this->createTeacher();
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'كورس الأحياء العام',
            'stage'      => 'sec',
            'grade_ar'   => 'الصف الثالث الثانوي',
        ]);

        $group = StudyGroup::create([
            'teacher_id'       => $teacher->id,
            'course_id'        => $course->id,
            'name_ar'          => 'مجموعة الدقي',
            'stage'            => 'sec',
            'grade_ar'         => 'الصف الثالث الثانوي',
            'subject_ar'       => 'الأحياء',
            'center_name'      => 'سنتر الدقي النخبة',
            'governorate'      => 'الجيزة',
            'area'             => 'الدقي',
            'price_egp'        => 350.00,
            'is_active'        => true,
        ]);

        $s1 = $this->createStudent('طالب 1', 's1@motafawweq.com');
        $s2 = $this->createStudent('طالب 2', 's2@motafawweq.com');

        GroupEnrollment::create([
            'study_group_id'  => $group->id,
            'student_id'      => $s1->id,
            'status'          => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 90,
        ]);

        GroupEnrollment::create([
            'study_group_id'  => $group->id,
            'student_id'      => $s2->id,
            'status'          => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 100,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/analytics/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.totalStudentsAudited', 2)
            ->assertJsonPath('data.averageAttendancePct', 95)
            ->assertJsonPath('data.revenueTotalEgp', 700)
            ->assertJsonPath('data.centerDistribution.سنتر الدقي النخبة', 2);
    }

    public function test_teacher_can_fetch_diagnostic_weak_areas(): void
    {
        $teacher = $this->createTeacher();
        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان الباب الأول',
            'duration_minutes' => 30,
            'full_mark'        => 10,
            'status'           => ExamStatus::ACTIVE,
        ]);

        $q = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ,
            'points'          => 10,
            'question_ar'     => 'ما هو ناتج التحلل السكري النهائي؟',
            'options_ar'      => ['أ', 'ب'],
            'correct_index'   => 0,
            'explanation_ar'  => 'حمض البيروفيك هو الناتج النهائي.',
        ]);

        $sub = ExamSubmission::create([
            'exam_id'      => $exam->id,
            'student_id'   => $this->createStudent('طالب مجرب', 'try@motafawweq.com')->id,
            'status'       => ExamSubmissionStatus::GRADED,
            'submitted_at' => now(),
        ]);

        // Wrong answer
        ExamSubmissionAnswer::create([
            'exam_submission_id' => $sub->id,
            'exam_question_id'   => $q->id,
            'selected_index'     => 1,
            'points_awarded'     => 0,
            'is_correct'         => false,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/analytics/weak-areas');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.questionAr', 'ما هو ناتج التحلل السكري النهائي؟')
            ->assertJsonPath('data.0.errorRatePct', 100);
    }

    public function test_teacher_can_fetch_at_risk_radar_and_resolve_signal(): void
    {
        $teacher = $this->createTeacher();
        $group = StudyGroup::create([
            'teacher_id'  => $teacher->id,
            'name_ar'     => 'مجموعة الإنذار',
            'stage'       => 'sec',
            'grade_ar'    => 'الصف الثالث الثانوي',
            'subject_ar'  => 'الأحياء',
            'center_name' => 'سنتر الرواد',
            'governorate' => 'القاهرة',
            'area'        => 'مدينة نصر',
            'is_active'   => true,
        ]);

        $strugglingStudent = $this->createStudent('ماجد كمال نصار', 'maged@motafawweq.com');

        // Low attendance (50%) & low quiz (40%) triggers high risk
        GroupEnrollment::create([
            'study_group_id'  => $group->id,
            'student_id'      => $strugglingStudent->id,
            'status'          => EnrollmentStatus::ENROLLED,
            'attendance_rate' => 50,
            'avg_quiz_score'  => 40,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Fetch radar
        $radarResponse = $this->getJson('/api/v1/teacher/analytics/at-risk-radar');
        $radarResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nameAr', 'ماجد كمال نصار')
            ->assertJsonPath('data.0.riskLevel', RiskLevel::HIGH->value)
            ->assertJsonPath('data.0.status', RiskSignalStatus::DETECTED->value);

        $signalUuid = $radarResponse->json('data.0.id');

        // Notify parent action
        $notifyResponse = $this->postJson("/api/v1/teacher/analytics/at-risk/{$signalUuid}/resolve", [
            'action' => 'notify_parent',
        ]);
        $notifyResponse->assertOk()
            ->assertJsonPath('data.status', RiskSignalStatus::NOTIFIED->value);

        // Resolve action
        $resolveResponse = $this->postJson("/api/v1/teacher/analytics/at-risk/{$signalUuid}/resolve", [
            'action' => 'resolve',
        ]);
        $resolveResponse->assertOk()
            ->assertJsonPath('data.status', RiskSignalStatus::RESOLVED->value);
    }

    public function test_teacher_can_fetch_executive_dashboard_summary(): void
    {
        $teacher = $this->createTeacher();
        $lesson = Lesson::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'حصة البناء الضوئي',
            'duration_seconds' => 2400,
        ]);

        \App\Models\LessonConceptGraph::create([
            'lesson_id'  => $lesson->id,
            'teacher_id' => $teacher->id,
            'tree_data'  => array_fill(0, 12, ['concept' => 'node']),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.welcome.teacherName', $teacher->name)
            ->assertJsonPath('data.welcome.teacherCode', 'TCH-' . str_pad((string) $teacher->id, 4, '0', STR_PAD_LEFT))
            ->assertJsonPath('data.recentLessons.0.titleAr', 'حصة البناء الضوئي')
            ->assertJsonPath('data.recentLessons.0.stats.conceptsCount', 12);
    }

    public function test_anti_idor_prevents_teacher_from_resolving_another_teachers_risk_signal(): void
    {
        $teacherA = $this->createTeacher('t.ana.a@motafawweq.com');
        $teacherB = $this->createTeacher('t.ana.b@motafawweq.com');
        $student = $this->createStudent('طالب محايد', 'std.ana@motafawweq.com');

        $signal = StudentRiskSignal::create([
            'teacher_id'      => $teacherA->id,
            'student_id'      => $student->id,
            'risk_level'      => RiskLevel::HIGH,
            'risk_reasons_ar' => 'غياب متكرر',
            'status'          => RiskSignalStatus::DETECTED,
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/teacher/analytics/at-risk/{$signal->uuid}/resolve", [
            'action' => 'resolve',
        ]);

        $response->assertNotFound();
    }
}
