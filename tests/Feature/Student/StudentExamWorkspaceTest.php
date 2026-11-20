<?php

namespace Tests\Feature\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentExamWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(): User
    {
        return User::factory()->create([
            'name' => 'د. مصطفى الفيزياء',
            'email' => 'exam.teacher.'.random_int(1000, 9999).'@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'مازن طارق'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'exam.student.'.random_int(1000, 9999).'@motafawweq.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(100000, 999999),
        ]);
    }

    protected function enrollStudent(User $student, User $teacher): void
    {
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'name_ar' => 'مجموعة الفيزياء',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة A',
            'price_egp' => 400,
            'join_code' => 'EXM-'.random_int(1000, 9999),
            'status' => 'active',
        ]);

        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);
    }

    public function test_full_mcq_and_true_false_exam_flow_grading_review_and_result_endpoint(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $otherStudent = $this->createStudent('طالب آخر');

        $this->enrollStudent($student, $teacher);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'اختبار الفصل الأول شامل',
            'course_title_ar' => 'الفيزياء الكهربية',
            'duration_minutes' => 30,
            'full_mark' => 20,
            'pass_score' => 10,
            'questions_count' => 2,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
            'is_bubble_sheet' => true,
        ]);

        $q1 = ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'وحدة قياس شدة التيار الكهربي هي؟',
            'options_ar' => ['الفولت', 'الأمبير', 'الأوم', 'الوات'],
            'correct_index' => 1,
            'explanation_ar' => 'الأمبير هو وحدة قياس شدة التيار في النظام الدولي.',
        ]);

        $q2 = ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_number' => 2,
            'type' => ExamQuestionType::TRUE_FALSE,
            'points' => 10,
            'question_ar' => 'تزداد مقاومة موصل بزيادة طوله عند ثبوت درجة الحرارة ومساحة المقطع.',
            'correct_bool' => true,
            'explanation_ar' => 'العلاقة طردية بين المقاومة والطول.',
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Start exam: must return questions WITHOUT leaking correct answers
        $startRes = $this->postJson("/api/v1/student/exams/{$exam->uuid}/start")
            ->assertOk()
            ->assertJsonPath('data.examId', $exam->uuid)
            ->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.questions.0.type', 'mcq')
            ->assertJsonPath('data.questions.1.type', 'true_false');

        $this->assertArrayNotHasKey('correctIndex', $startRes->json('data.questions.0'));
        $this->assertArrayNotHasKey('correctBool', $startRes->json('data.questions.1'));
        $this->assertArrayNotHasKey('explanationAr', $startRes->json('data.questions.0'));

        // 2. Catalog now shows in_progress with remainingSeconds
        $this->getJson('/api/v1/student/exams')
            ->assertOk()
            ->assertJsonPath('data.0.submissionStatus', 'in_progress')
            ->assertJsonPath('data.0.showResults', true);

        // 3. Submit: Q1 correct (index 1), Q2 wrong (false) -> 10/20 (50%, passed, +30 XP)
        $submitRes = $this->postJson("/api/v1/student/exams/{$exam->uuid}/submit", [
            'tabSwitchesCount' => 1,
            'answers' => [
                ['questionUuid' => $q1->uuid, 'selectedIndex' => 1],
                ['questionUuid' => $q2->uuid, 'selectedBool' => false],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 10)
            ->assertJsonPath('data.fullMark', 20)
            ->assertJsonPath('data.percentage', 50)
            ->assertJsonPath('data.isPassed', true)
            ->assertJsonPath('data.correctCount', 1)
            ->assertJsonPath('data.wrongCount', 1)
            ->assertJsonPath('data.unansweredCount', 0)
            ->assertJsonPath('data.xpAwarded', 3)
            ->assertJsonCount(2, 'data.review')
            ->assertJsonPath('data.review.0.isCorrect', true)
            ->assertJsonPath('data.review.0.correctIndex', 1)
            ->assertJsonPath('data.review.1.isCorrect', false)
            ->assertJsonPath('data.review.1.correctBool', true);

        // 4. Student can re-fetch full result & review anytime via GET /result
        $this->getJson("/api/v1/student/exams/{$exam->uuid}/result")
            ->assertOk()
            ->assertJsonPath('data.score', 10)
            ->assertJsonPath('data.xpAwarded', 3)
            ->assertJsonCount(2, 'data.review');

        // 5. Unenrolled student cannot access result (403 IDOR blocked)
        Sanctum::actingAs($otherStudent, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/exams/{$exam->uuid}/result")->assertForbidden();
    }

    public function test_hidden_results_and_essay_exams_withhold_answers_and_guards_future_and_expired_attempts(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $this->enrollStudent($student, $teacher);

        // Future exam must be blocked from early start
        $futureExam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'امتحان غداً',
            'duration_minutes' => 30,
            'full_mark' => 10,
            'pass_score' => 5,
            'status' => ExamStatus::ACTIVE,
            'starts_at' => now()->addHours(4),
        ]);
        ExamQuestion::create([
            'exam_id' => $futureExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'سؤال مستقبلي',
            'options_ar' => ['أ', 'ب'],
            'correct_index' => 0,
        ]);

        // Essay exam: status stays 'submitted' (pending manual grading), review withheld
        $essayExam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'اختبار مقالي',
            'duration_minutes' => 20,
            'full_mark' => 20,
            'pass_score' => 10,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);
        $essayQ = ExamQuestion::create([
            'exam_id' => $essayExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::ESSAY,
            'points' => 20,
            'question_ar' => 'اشرح قانون كيرشوف الأول.',
            'model_answer_ar' => 'مجموع التيارات الداخلة يساوي الخارجة.',
        ]);

        // Expired in_progress attempt auto-closes cleanly when student retries start
        $expiredExam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'اختبار منتهي الوقت',
            'duration_minutes' => 15,
            'full_mark' => 10,
            'pass_score' => 5,
            'status' => ExamStatus::ACTIVE,
            'show_results' => false,
        ]);
        ExamQuestion::create([
            'exam_id' => $expiredExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'question_ar' => 'سؤال',
            'options_ar' => ['1', '2'],
            'correct_index' => 0,
        ]);
        ExamSubmission::create([
            'exam_id' => $expiredExam->id,
            'student_id' => $student->id,
            'started_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
            'full_mark' => 10,
            'status' => ExamSubmissionStatus::IN_PROGRESS,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Future exam blocked
        $this->postJson("/api/v1/student/exams/{$futureExam->uuid}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exam']);

        // Expired attempt auto-closes cleanly instead of hanging at 0s
        $this->postJson("/api/v1/student/exams/{$expiredExam->uuid}/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['exam']);

        // Hidden results exam withholds score & review even after auto-closing
        $this->getJson("/api/v1/student/exams/{$expiredExam->uuid}/result")
            ->assertOk()
            ->assertJsonPath('data.showResults', false)
            ->assertJsonPath('data.score', null)
            ->assertJsonCount(0, 'data.review');

        // Essay exam submits cleanly with isPendingManualGrading = true and empty review
        $this->postJson("/api/v1/student/exams/{$essayExam->uuid}/submit", [
            'answers' => [
                ['questionUuid' => $essayQ->uuid, 'essayAnswerText' => 'مجموع التيارات عند نقطة تفرع يساوي صفر.'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.isPendingManualGrading', true)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', null)
            ->assertJsonPath('data.isPassed', null)
            ->assertJsonCount(0, 'data.review');
    }
}
