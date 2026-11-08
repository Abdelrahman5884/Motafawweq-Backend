<?php

namespace Tests\Feature\Teacher;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherExamTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher.exams@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_teacher_can_create_exam_with_mcq_true_false_and_essay_questions(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'titleAr'         => 'امتحان البناء الضوئي والتنفس الخلوي',
            'titleEn'         => 'Photosynthesis Exam',
            'courseTitleAr'   => 'ماستر كلاس الأحياء (3 ثانوي)',
            'durationMinutes' => 45,
            'passScore'       => 15,
            'dueDate'         => '2026-10-30',
            'isBubbleSheet'   => true,
            'questions'       => [
                [
                    'type'          => ExamQuestionType::MCQ->value,
                    'points'        => 10,
                    'difficulty'    => 'متوسط',
                    'questionAr'    => 'ما هو المانح المباشر لإلكترونات تعويض كلوروفيل (أ)؟',
                    'optionsAr'     => ['الماء H2O', 'غاز CO2', 'مركب NADPH', 'جزيء الجلوكوز'],
                    'correctIndex'  => 0,
                    'explanationAr' => 'انشطار الماء ضوئياً هو المصدر المباشر.',
                ],
                [
                    'type'          => ExamQuestionType::TRUE_FALSE->value,
                    'points'        => 10,
                    'difficulty'    => 'مباشر',
                    'questionAr'    => 'تحدث دورة كريبس بالكامل داخل الغشاء الداخلي للميتوكوندريا.',
                    'correctBool'   => false,
                    'explanationAr' => 'تحدث داخل مادة الأساس.',
                ],
                [
                    'type'           => ExamQuestionType::ESSAY->value,
                    'points'         => 10,
                    'difficulty'     => 'مستويات تفكير عليا',
                    'questionAr'     => 'علل: توقف التفاعلات اللاضوئية بعد فترة وجيزة من انعدام الضوء.',
                    'modelAnswerAr'  => 'تعتمد التفاعلات اللاضوئية على نواتج المرحلة الضوئية ATP و NADPH.',
                    'rubricPointsAr' => ['ذكر الاعتماد على نواتج الضوء (درجة)', 'تحديد نفاد ATP (درجة)'],
                    'explanationAr'  => 'سؤال ربط تكاملي.',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/teacher/exams', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titleAr', 'امتحان البناء الضوئي والتنفس الخلوي')
            ->assertJsonPath('data.questionsCount', 3)
            ->assertJsonPath('data.fullMark', 30)
            ->assertJsonPath('data.isBubbleSheet', true)
            ->assertJsonCount(3, 'data.questions');

        $this->assertDatabaseHas('exams', [
            'teacher_id'      => $teacher->id,
            'title_ar'        => 'امتحان البناء الضوئي والتنفس الخلوي',
            'questions_count' => 3,
            'full_mark'       => 30,
        ]);

        $this->assertDatabaseHas('exam_questions', [
            'type'            => ExamQuestionType::MCQ->value,
            'question_number' => 1,
            'correct_index'   => 0,
        ]);

        $this->assertDatabaseHas('exam_questions', [
            'type'            => ExamQuestionType::TRUE_FALSE->value,
            'question_number' => 2,
            'correct_bool'    => false,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.exam.created',
        ]);
    }

    public function test_teacher_can_list_and_search_exams_and_filter_by_status(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam1 = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'امتحان الأحياء الشامل',
            'status'     => ExamStatus::ACTIVE->value,
        ]);

        $exam2 = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'امتحان الكيمياء النهائي',
            'status'     => ExamStatus::CLOSED->value,
        ]);

        // Search by query
        $this->getJson('/api/v1/teacher/exams?q=الأحياء')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exam1->uuid);

        // Filter by status closed
        $this->getJson('/api/v1/teacher/exams?status=closed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $exam2->uuid);
    }

    public function test_teacher_can_fetch_exam_details_and_filter_by_question_type(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'كويز سريع',
            'status'     => ExamStatus::ACTIVE->value,
        ]);

        ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ->value,
            'points'          => 5,
            'question_ar'     => 'سؤال اختيار من متعدد',
            'options_ar'      => ['أ', 'ب', 'ج', 'د'],
            'correct_index'   => 1,
        ]);

        ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 2,
            'type'            => ExamQuestionType::ESSAY->value,
            'points'          => 10,
            'question_ar'     => 'سؤال مقالي',
            'model_answer_ar' => 'نموذج الإجابة المقالي',
        ]);

        $exam->recalculateMetrics();

        // Fetch all questions
        $this->getJson("/api/v1/teacher/exams/{$exam->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'data.questions');

        // Fetch only MCQ questions
        $this->getJson("/api/v1/teacher/exams/{$exam->uuid}?question_type=mcq")
            ->assertOk()
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.type', ExamQuestionType::MCQ->value);
    }

    public function test_teacher_can_update_exam_settings(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam = Exam::create([
            'teacher_id'       => $teacher->id,
            'title_ar'         => 'امتحان تجريبي',
            'duration_minutes' => 30,
            'full_mark'        => 20,
        ]);

        $response = $this->putJson("/api/v1/teacher/exams/{$exam->uuid}", [
            'titleAr'         => 'امتحان تجريبي معدل ومطور',
            'durationMinutes' => 60,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.titleAr', 'امتحان تجريبي معدل ومطور')
            ->assertJsonPath('data.durationMinutes', 60);

        $this->assertDatabaseHas('exams', [
            'id'               => $exam->id,
            'title_ar'         => 'امتحان تجريبي معدل ومطور',
            'duration_minutes' => 60,
        ]);
    }

    public function test_teacher_can_toggle_exam_status_and_results(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam = Exam::create([
            'teacher_id'   => $teacher->id,
            'title_ar'     => 'امتحان دوري',
            'status'       => ExamStatus::ACTIVE->value,
            'show_results' => true,
        ]);

        // Toggle status (active -> closed)
        $this->patchJson("/api/v1/teacher/exams/{$exam->uuid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.status', ExamStatus::CLOSED->value);

        $this->assertEquals(ExamStatus::CLOSED, $exam->fresh()->status);

        // Toggle results visibility (true -> false)
        $this->patchJson("/api/v1/teacher/exams/{$exam->uuid}/toggle-results")
            ->assertOk()
            ->assertJsonPath('data.showResults', false);

        $this->assertFalse((bool) $exam->fresh()->show_results);
    }

    public function test_teacher_can_duplicate_exam_with_questions_matrix(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'امتحان نصف الفصل الدراسي',
            'full_mark'  => 10,
        ]);

        ExamQuestion::create([
            'exam_id'         => $exam->id,
            'question_number' => 1,
            'type'            => ExamQuestionType::MCQ->value,
            'points'          => 10,
            'question_ar'     => 'سؤال مستنسخ',
            'options_ar'      => ['1', '2'],
            'correct_index'   => 0,
        ]);

        $response = $this->postJson("/api/v1/teacher/exams/{$exam->uuid}/duplicate");

        $response->assertCreated()
            ->assertJsonPath('data.titleAr', 'امتحان نصف الفصل الدراسي (نسخة مكررة)')
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.questionAr', 'سؤال مستنسخ');

        $this->assertEquals(2, Exam::where('teacher_id', $teacher->id)->count());
    }

    public function test_teacher_can_soft_delete_exam(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'امتحان للحذف',
        ]);

        $response = $this->deleteJson("/api/v1/teacher/exams/{$exam->uuid}");
        $response->assertOk();

        $this->assertSoftDeleted('exams', ['id' => $exam->id]);
    }

    public function test_cross_teacher_idor_on_exams_is_strictly_blocked_and_logged(): void
    {
        $teacherA = $this->createTeacher('teacher.a.exam@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.b.exam@motafawweq.com');

        $examA = Exam::create([
            'teacher_id' => $teacherA->id,
            'title_ar'   => 'امتحان سري للمعلم أ',
        ]);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        // Teacher B tries to view Teacher A's exam
        $this->getJson("/api/v1/teacher/exams/{$examA->uuid}")
            ->assertForbidden();

        // Teacher B tries to edit Teacher A's exam
        $this->putJson("/api/v1/teacher/exams/{$examA->uuid}", [
            'titleAr' => 'اختراق الامتحان',
        ])->assertForbidden();

        // Teacher B tries to duplicate Teacher A's exam
        $this->postJson("/api/v1/teacher/exams/{$examA->uuid}/duplicate")
            ->assertForbidden();

        // Teacher B tries to delete Teacher A's exam
        $this->deleteJson("/api/v1/teacher/exams/{$examA->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}

