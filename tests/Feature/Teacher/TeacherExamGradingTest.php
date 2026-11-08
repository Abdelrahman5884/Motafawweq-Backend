<?php

namespace Tests\Feature\Teacher;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherExamGradingTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.grading@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $email = 'student.tester@motafawweq.com'): User
    {
        return User::factory()->create([
            'name'              => 'أحمد المتفوق',
            'email'             => $email,
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_student_can_submit_objective_exam_and_get_auto_graded_immediately(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان الأحياء الموضوعي',
            'duration_minutes' => 30,
            'full_mark'        => 20,
            'pass_score'       => 10,
            'questions_count'  => 2,
            'status'           => ExamStatus::ACTIVE,
            'show_results'     => true,
        ]);

        $q1 = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ,
            'points'          => 10,
            'question_ar'     => 'أي الجزيئات التالية ينتج مباشرة من التحلل السكري؟',
            'options_ar'      => ['حمض البيروفيك', 'حمض الستريك', 'الأسيتيل كوانزيم'],
            'correct_index'   => 0,
        ]);

        $q2 = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 2,
            'type'            => ExamQuestionType::TRUE_FALSE,
            'points'          => 10,
            'question_ar'     => 'الريبوسومات هي المسؤولة عن بناء البروتين في الخلية.',
            'correct_bool'    => true,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $payload = [
            'startedAt' => now()->subMinutes(15)->toIso8601String(),
            'answers'   => [
                [
                    'questionUuid'  => $q1->uuid,
                    'selectedIndex' => 0, // Correct
                ],
                [
                    'questionUuid' => $q2->uuid,
                    'selectedBool' => true, // Correct
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/exams/{$exam->uuid}/submit", $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ExamSubmissionStatus::GRADED->value)
            ->assertJsonPath('data.score', 20)
            ->assertJsonPath('data.fullMark', 20)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.isPassed', true)
            ->assertJsonPath('data.isFullMark', true);

        $this->assertDatabaseHas('exam_submissions', [
            'exam_id'    => $exam->id,
            'student_id' => $student->id,
            'status'     => ExamSubmissionStatus::GRADED->value,
            'score'      => 20.00,
            'is_passed'  => true,
        ]);

        $exam->refresh();
        $this->assertEquals(1, $exam->submissions_count);
        $this->assertEquals(20.00, $exam->avg_score);
        $this->assertEquals(100.00, $exam->pass_rate);
        $this->assertEquals(1, $exam->top_score_count);
    }

    public function test_submitting_exam_with_essay_question_sets_status_to_submitted(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('essay.student@motafawweq.com');

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان الأحياء الشامل مع مقالي',
            'duration_minutes' => 60,
            'full_mark'        => 30,
            'pass_score'       => 15,
            'questions_count'  => 2,
            'status'           => ExamStatus::ACTIVE,
            'show_results'     => true,
        ]);

        $q1 = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ,
            'points'          => 10,
            'question_ar'     => 'سؤال اختياري',
            'options_ar'      => ['أ', 'ب'],
            'correct_index'   => 1,
        ]);

        $q2 = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 2,
            'type'            => ExamQuestionType::ESSAY,
            'points'          => 20,
            'question_ar'     => 'اشرح بالتفصيل آلية انقباض اللييفة العضلية حسب نظرية الخيوط المنزلقة.',
            'model_answer_ar' => 'تنزلق خيوط الأكتين فوق الميوسين بمساعدة الروابط المستعرضة وأيونات الكالسيوم وATP.',
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $payload = [
            'answers' => [
                [
                    'questionUuid'  => $q1->uuid,
                    'selectedIndex' => 1, // 10 points
                ],
                [
                    'questionUuid'    => $q2->uuid,
                    'essayAnswerText' => 'تتصل الروابط المستعرضة الممتدة من الميوسين بخيوط الأكتين وتسحبها في وجود طاقة ATP.',
                ],
            ],
        ];

        $response = $this->postJson("/api/v1/exams/{$exam->uuid}/submit", $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ExamSubmissionStatus::SUBMITTED->value)
            ->assertJsonPath('data.score', 10); // Preliminary objective score

        $this->assertDatabaseHas('exam_submissions', [
            'exam_id'    => $exam->id,
            'student_id' => $student->id,
            'status'     => ExamSubmissionStatus::SUBMITTED->value,
            'score'      => 10.00,
        ]);
    }

    public function test_cannot_submit_exam_when_exam_is_closed(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('closed.test@motafawweq.com');

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان مغلق',
            'duration_minutes' => 30,
            'full_mark'        => 10,
            'pass_score'       => 5,
            'questions_count'  => 1,
            'status'           => ExamStatus::CLOSED,
        ]);

        $q1 = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ,
            'points'          => 10,
            'question_ar'     => 'سؤال تجريبي',
            'options_ar'      => ['أ', 'ب'],
            'correct_index'   => 0,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $response = $this->postJson("/api/v1/exams/{$exam->uuid}/submit", [
            'answers' => [
                ['questionUuid' => $q1->uuid, 'selectedIndex' => 0],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('exam');
    }

    public function test_teacher_can_list_submissions_for_their_exam(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('list.student@motafawweq.com');

        $exam = Exam::create([
            'teacher_id'        => $teacher->id,
            'title_ar'          => 'امتحان الأحياء للرصد',
            'duration_minutes'  => 45,
            'full_mark'         => 50,
            'pass_score'        => 25,
            'questions_count'   => 1,
            'status'            => ExamStatus::ACTIVE,
            'submissions_count' => 1,
            'avg_score'         => 45.0,
            'pass_rate'         => 100.0,
        ]);

        $submission = ExamSubmission::create([
            'exam_id'      => $exam->id,
            'student_id'   => $student->id,
            'score'        => 45,
            'full_mark'    => 50,
            'percentage'   => 90.0,
            'is_passed'    => true,
            'is_full_mark' => false,
            'status'       => ExamSubmissionStatus::GRADED,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson("/api/v1/teacher/exams/{$exam->uuid}/submissions");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.avgScore', 45)
            ->assertJsonPath('data.0.id', $submission->uuid)
            ->assertJsonPath('data.0.student.name', $student->name)
            ->assertJsonPath('data.0.score', 45);
    }

    public function test_teacher_can_view_detailed_submission_with_student_answers(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('details.student@motafawweq.com');

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان التفاصيل',
            'duration_minutes' => 45,
            'full_mark'        => 20,
            'pass_score'       => 10,
            'questions_count'  => 1,
            'status'           => ExamStatus::ACTIVE,
        ]);

        $q = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::ESSAY,
            'points'          => 20,
            'question_ar'     => 'علل: سرعة استجابة الخلايا اللمفاوية التائية في الاستجابة المناعية الثانوية؟',
            'model_answer_ar' => 'لوجود خلايا الذاكرة التائية المتكونة في الاستجابة الأولية.',
        ]);

        $submission = ExamSubmission::create([
            'exam_id'      => $exam->id,
            'student_id'   => $student->id,
            'score'        => 0,
            'full_mark'    => 20,
            'status'       => ExamSubmissionStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

        $submission->answers()->create([
            'exam_question_id'  => $q->id,
            'essay_answer_text' => 'بسبب وجود خلايا الذاكرة المناعية.',
            'points_awarded'    => 0,
            'is_correct'        => null,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson("/api/v1/teacher/exams/{$exam->uuid}/submissions/{$submission->uuid}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $submission->uuid)
            ->assertJsonPath('data.answers.0.essayAnswerText', 'بسبب وجود خلايا الذاكرة المناعية.')
            ->assertJsonPath('data.answers.0.modelAnswerAr', 'لوجود خلايا الذاكرة التائية المتكونة في الاستجابة الأولية.');
    }

    public function test_teacher_can_grade_essay_questions_and_recalculate_metrics(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('grade.essay@motafawweq.com');

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان تصحيح المقالي',
            'duration_minutes' => 45,
            'full_mark'        => 20,
            'pass_score'       => 10,
            'questions_count'  => 1,
            'status'           => ExamStatus::ACTIVE,
        ]);

        $q = ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::ESSAY,
            'points'          => 20,
            'question_ar'     => 'اشرح أهمية هرمون الأوكسين في نمو النبات.',
            'model_answer_ar' => 'ينظم عمليات النمو والانتحاء الضوئي والجاذبي.',
        ]);

        $submission = ExamSubmission::create([
            'exam_id'      => $exam->id,
            'student_id'   => $student->id,
            'score'        => 0,
            'full_mark'    => 20,
            'status'       => ExamSubmissionStatus::SUBMITTED,
            'submitted_at' => now(),
        ]);

        $submission->answers()->create([
            'exam_question_id'  => $q->id,
            'essay_answer_text' => 'يؤثر على استطالة الخلايا النباتية والانتحاء.',
            'points_awarded'    => 0,
            'is_correct'        => null,
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'answers' => [
                [
                    'questionUuid'    => $q->uuid,
                    'pointsAwarded'   => 18,
                    'teacherFeedback' => 'إجابة نموذجية وممتازة!',
                ],
            ],
            'teacherFeedback' => 'أحسنت يا أحمد، استمر على هذا المستوى.',
        ];

        $response = $this->patchJson("/api/v1/teacher/exams/{$exam->uuid}/submissions/{$submission->uuid}/grade-essay", $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', ExamSubmissionStatus::GRADED->value)
            ->assertJsonPath('data.score', 18)
            ->assertJsonPath('data.percentage', 90)
            ->assertJsonPath('data.isPassed', true)
            ->assertJsonPath('data.teacherFeedback', 'أحسنت يا أحمد، استمر على هذا المستوى.')
            ->assertJsonPath('data.answers.0.pointsAwarded', 18)
            ->assertJsonPath('data.answers.0.teacherFeedback', 'إجابة نموذجية وممتازة!');

        $exam->refresh();
        $this->assertEquals(1, $exam->submissions_count);
        $this->assertEquals(18.00, $exam->avg_score);
        $this->assertEquals(100.00, $exam->pass_rate);
        $this->assertEquals(1, $exam->top_score_count); // 18/20 = 90% (>= 90%)
    }

    public function test_anti_idor_prevents_teacher_from_viewing_or_grading_another_teachers_exam_submissions(): void
    {
        $teacherA = $this->createTeacher('teacher.a@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b@motafawweq.com');
        $student = $this->createStudent('idor.student@motafawweq.com');

        $examA = Exam::create([
            'teacher_id'       => $teacherA->id,
            'title_ar'         => 'امتحان الأستاذ أ',
            'duration_minutes' => 30,
            'full_mark'        => 10,
            'pass_score'       => 5,
            'questions_count'  => 1,
            'status'           => ExamStatus::ACTIVE,
        ]);

        $submission = ExamSubmission::create([
            'exam_id'      => $examA->id,
            'student_id'   => $student->id,
            'score'        => 8,
            'full_mark'    => 10,
            'status'       => ExamSubmissionStatus::GRADED,
            'submitted_at' => now(),
        ]);

        // Teacher B attempts to view submissions of Teacher A's exam
        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $responseView = $this->getJson("/api/v1/teacher/exams/{$examA->uuid}/submissions");
        $responseView->assertForbidden();

        // Teacher B attempts to grade Teacher A's submission
        $responseGrade = $this->patchJson("/api/v1/teacher/exams/{$examA->uuid}/submissions/{$submission->uuid}/grade-essay", [
            'answers' => [
                ['questionUuid' => 'some-uuid', 'pointsAwarded' => 5],
            ],
        ]);
        $responseGrade->assertForbidden();

        // Verify Anti-IDOR security audit log recorded
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
        ]);
    }
}
