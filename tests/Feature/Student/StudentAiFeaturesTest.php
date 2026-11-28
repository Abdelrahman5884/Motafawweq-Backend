<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
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
use App\Models\LessonTranscript;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentAiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(): User
    {
        return User::factory()->create([
            'name' => 'أ. محمود الفيزياء',
            'email' => 'ai.teacher.'.random_int(1000, 9999).'@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudentWithPlan(StudentPlanTier $tier = StudentPlanTier::PRO): User
    {
        $student = User::factory()->create([
            'name' => 'يوسف أحمد',
            'email' => 'ai.student.'.random_int(1000, 9999).'@motafawweq.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(100000, 999999),
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'grade_ar' => 'الصف الثالث الثانوي',
        ]);

        if ($tier !== StudentPlanTier::FREE) {
            StudentSubscription::create([
                'student_id' => $student->id,
                'plan_tier' => $tier->value,
                'plan_name_ar' => $tier->labelAr(),
                'plan_name_en' => $tier->labelEn(),
                'billing_period' => 'monthly',
                'amount_egp' => $tier === StudentPlanTier::PRO ? 199 : 99,
                'base_amount_egp' => $tier === StudentPlanTier::PRO ? 199 : 99,
                'discount_amount_egp' => 0,
                'status' => 'active',
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addMonth(),
            ]);
        }

        return $student;
    }

    protected function enrollStudent(User $student, User $teacher, Course $course): void
    {
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الفيزياء',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة 1',
            'price_egp' => 300,
            'join_code' => 'PHY-'.random_int(1000, 9999),
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

    public function test_smart_lecture_stores_audio_for_signed_playback_supports_url_and_docx_and_retry(): void
    {
        Storage::fake('local');

        Http::fake([
            '*/api/v1/stt/jobs' => Http::response([
                'job_id' => 'stt-file-1',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'mohammedaly22/QwenCleo-ASR',
            ], 202),
            '*/api/v1/stt/jobs/from-url' => Http::response([
                'job_id' => 'stt-url-1',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'downloading_media',
                'model' => 'mohammedaly22/QwenCleo-ASR',
            ], 202),
            '*/api/v1/analysis/jobs/from-document' => Http::response([
                'job_id' => 'doc-docx-1',
                'status' => 'queued',
                'progress' => 12,
                'stage' => 'extracting_document',
                'model' => 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit',
            ], 202),
        ]);

        $student = $this->createStudentWithPlan(StudentPlanTier::PRO);
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Audio upload persists local file + returns cryptographic temporary signed mediaUrl
        $audioFile = UploadedFile::fake()->create('my-recording.webm', 120, 'audio/webm');
        $res = $this->post('/api/v1/student/converted-lectures', [
            'title_ar' => 'تسجيل المراجعة السريعة',
            'file' => $audioFile,
            'outputs' => ['graph', 'transcript', 'topics', 'quiz'],
        ], ['Idempotency-Key' => 'conv-audio-1'])->assertCreated();

        $lectureId = $res->json('data.id');
        $mediaUrl = $res->json('data.mediaUrl');
        $this->assertNotEmpty($mediaUrl);
        $this->assertSame('audio', $res->json('data.mediaType'));

        // Signed stream URL serves stored audio cleanly; tampered URL is blocked
        $this->get($mediaUrl)->assertOk();
        $this->get("/api/v1/media/student-converted-lectures/{$lectureId}/media/stream")->assertForbidden();

        // 2. YouTube HTTPS URL accepted & dispatched to URL STT; SSRF localhost rejected
        $this->postJson('/api/v1/student/converted-lectures', [
            'title_ar' => 'محاضرة يوتيوب',
            'embed_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'outputs' => ['graph', 'transcript'],
        ], ['Idempotency-Key' => 'conv-url-1'])
            ->assertCreated()
            ->assertJsonPath('data.mediaType', 'url')
            ->assertJsonPath('data.sourceUrl', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->postJson('/api/v1/student/converted-lectures', [
            'title_ar' => 'محاولة SSRF',
            'embed_url' => 'https://127.0.0.1/secret',
            'outputs' => ['transcript'],
        ], ['Idempotency-Key' => 'conv-url-ssrf'])->assertUnprocessable();

        // 3. DOCX Word document accepted & routed to document analysis endpoint
        $docxFile = UploadedFile::fake()->create(
            'notes.docx',
            40,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        $this->post('/api/v1/student/converted-lectures', [
            'title_ar' => 'ملخص وورد',
            'file' => $docxFile,
            'outputs' => ['graph', 'topics', 'quiz'],
        ], ['Idempotency-Key' => 'conv-docx-1'])
            ->assertCreated()
            ->assertJsonPath('data.mediaType', 'document');

        // 4. Retry endpoint restarts processing cleanly on owned lecture
        $this->postJson("/api/v1/student/converted-lectures/{$lectureId}/retry")
            ->assertStatus(202);

        // 5. Delete cleans up stored media file too
        $this->deleteJson("/api/v1/student/converted-lectures/{$lectureId}")->assertOk();
        $this->get($mediaUrl)->assertNotFound();
    }

    public function test_student_accessible_lessons_and_direct_lesson_ai_quiz_with_idor_protection(): void
    {
        Http::fake([
            '*/api/v1/analysis/jobs' => Http::response([
                'job_id' => 'quiz-job-42',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit',
            ], 202),
            '*/api/v1/analysis/jobs/quiz-job-42' => Http::response([
                'job_id' => 'quiz-job-42',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit',
                    'device' => 'cuda',
                    'quantization' => 'nf4-4bit',
                    'language' => 'ar',
                    'processing_seconds' => 1.5,
                    'quiz_questions' => [
                        [
                            'id' => 'ai-q-1',
                            'questionAr' => 'ما وحدة قياس المقاومة الكهربائية؟',
                            'questionEn' => 'What is the unit of electrical resistance?',
                            'optionsAr' => ['الأوم', 'الأمبير', 'الفولت', 'الواط'],
                            'optionsEn' => ['Ohm', 'Ampere', 'Volt', 'Watt'],
                            'correctIndex' => 0,
                            'explanationAr' => 'تقاس المقاومة بالأوم حسب قانون أوم.',
                            'explanationEn' => 'Resistance is measured in Ohms.',
                        ],
                    ],
                ],
            ], 200),
            '*/api/v1/analysis/jobs/quiz-job-42*' => Http::response(null, 204),
        ]);

        $teacher = $this->createTeacher();
        $studentA = $this->createStudentWithPlan(StudentPlanTier::PLUS);
        $studentB = $this->createStudentWithPlan(StudentPlanTier::PLUS);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء الحديثة',
            'subject_ar' => 'الفيزياء',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'قانون أوم والمقاومة',
            'duration_seconds' => 600,
            'duration_label' => '10:00',
            'status' => LessonStatus::PUBLISHED,
        ]);

        LessonTranscript::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'speaker' => 'أ. محمود',
            'start_seconds' => 0,
            'timestamp_label' => '00:00',
            'text_ar' => 'قانون أوم يربط بين فرق الجهد وشدة التيار والمقاومة بوحدة الأوم.',
            'display_order' => 1,
        ]);

        $this->enrollStudent($studentA, $teacher, $course);
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/student/ai/lessons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lesson->uuid)
            ->assertJsonPath('data.0.hasTranscript', true);

        $startRes = $this->postJson("/api/v1/student/ai/lessons/{$lesson->uuid}/quiz", [
            'quiz_count' => 4,
        ])->assertStatus(202);

        $jobUuid = $startRes->json('data.job.id');
        $this->assertNotEmpty($jobUuid);

        // Poll completes & persists quizQuestions
        $this->getJson("/api/v1/student/ai/jobs/{$jobUuid}")
            ->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.quizQuestions.0.questionAr', 'ما وحدة قياس المقاومة الكهربائية؟');

        // Another student cannot read Student A's AI job (IDOR blocked)
        Sanctum::actingAs($studentB, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/ai/jobs/{$jobUuid}")->assertForbidden();
    }

    public function test_weak_areas_ai_coach_and_real_analytics_hydration(): void
    {
        Http::fake([
            '*/api/v1/analysis/jobs' => Http::response([
                'job_id' => 'coach-job-99',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit',
            ], 202),
            '*/api/v1/analysis/jobs/coach-job-99' => Http::response([
                'job_id' => 'coach-job-99',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit',
                    'device' => 'cuda',
                    'quantization' => 'nf4-4bit',
                    'language' => 'ar',
                    'processing_seconds' => 1.8,
                    'coach_report' => [
                        'overviewAr' => 'يوجد خلط في العلاقة العكسية بين المقاومة وشدة التيار.',
                        'overviewEn' => 'Confusion in inverse relation between resistance and current.',
                        'revisionPlanAr' => ['راجع قانون أوم', 'حل الكويز العلاجي'],
                        'revisionPlanEn' => ['Review Ohms Law', 'Solve remediation quiz'],
                        'diagnoses' => [
                            [
                                'id' => 'diag-1',
                                'topicAr' => 'قانون أوم',
                                'topicEn' => 'Ohms Law',
                                'subjectAr' => 'الفيزياء',
                                'severity' => 'high',
                                'rootCauseAr' => 'اخترت علاقة طردية بدلاً من عكسية.',
                                'rootCauseEn' => 'Picked direct instead of inverse.',
                                'actionTipAr' => 'تذكر I = V / R.',
                                'actionTipEn' => 'Remember I = V / R.',
                            ],
                        ],
                    ],
                    'quiz_questions' => [
                        [
                            'id' => 'ai-q-1',
                            'questionAr' => 'عند ثبوت الجهد، زيادة المقاومة تؤدي إلى:',
                            'questionEn' => 'At constant voltage, increasing resistance causes:',
                            'optionsAr' => ['نقص شدة التيار', 'زيادة شدة التيار', 'ثبات التيار', 'تضاعف الجهد'],
                            'optionsEn' => ['Decrease in current', 'Increase in current', 'Constant current', 'Double voltage'],
                            'correctIndex' => 0,
                            'explanationAr' => 'التيار يتناسب عكسياً مع المقاومة.',
                            'explanationEn' => 'Current is inversely proportional to resistance.',
                        ],
                    ],
                ],
            ], 200),
            '*/api/v1/analysis/jobs/coach-job-99*' => Http::response(null, 204),
        ]);

        $teacher = $this->createTeacher();
        $student = $this->createStudentWithPlan(StudentPlanTier::PRO);

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الفيزياء للثانوية العامة',
            'subject_ar' => 'الفيزياء',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);
        $this->enrollStudent($student, $teacher, $course);

        $exam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'course_title_ar' => 'الفيزياء',
            'title_ar' => 'اختبار التيار الكهربي',
            'duration_minutes' => 20,
            'full_mark' => 10,
            'pass_score' => 5,
            'questions_count' => 1,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);

        $question = ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'difficulty' => 'medium',
            'question_ar' => 'ما نوع العلاقة بين شدة التيار والمقاومة عند ثبوت فرق الجهد؟',
            'options_ar' => ['عكسية', 'طردية', 'ثابتة', 'لا توجد علاقة'],
            'correct_index' => 0,
            'explanation_ar' => 'من قانون أوم I = V/R العلاقة عكسية.',
        ]);

        $submission = ExamSubmission::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => ExamSubmissionStatus::GRADED,
            'score' => 0,
            'full_mark' => 10,
            'percentage' => 0,
            'is_passed' => false,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(40),
            'graded_at' => now()->subMinutes(40),
        ]);

        ExamSubmissionAnswer::create([
            'exam_submission_id' => $submission->id,
            'exam_question_id' => $question->id,
            'selected_index' => 1,
            'is_correct' => false,
            'points_earned' => 0,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Mistake bank now returns explicit Arabic answer strings
        $this->getJson('/api/v1/student/mistake-bank')
            ->assertOk()
            ->assertJsonPath('data.0.wrongAnswerGivenAr', 'طردية')
            ->assertJsonPath('data.0.correctAnswerAr', 'عكسية')
            ->assertJsonPath('data.0.subjectAr', 'الفيزياء');

        // Analytics returns real subjectAverages, weakAreas, gradedExamsCount
        $this->getJson('/api/v1/student/analytics')
            ->assertOk()
            ->assertJsonPath('data.gradedExamsCount', 1)
            ->assertJsonCount(1, 'data.subjectAverages')
            ->assertJsonCount(1, 'data.weakAreas');

        // Start AI Weak Areas diagnosis + remediation quiz
        $diagRes = $this->postJson('/api/v1/student/ai/weak-areas/diagnose', [
            'include_quiz' => true,
            'quiz_count' => 3,
        ])->assertStatus(202);

        $jobUuid = $diagRes->json('data.job.id');
        $this->getJson("/api/v1/student/ai/jobs/{$jobUuid}")
            ->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.coachReport.diagnoses.0.topicAr', 'قانون أوم')
            ->assertJsonPath('data.quizQuestions.0.correctIndex', 0);

        // Latest coach report endpoint returns persisted report on reload
        $this->getJson('/api/v1/student/ai/weak-areas/latest')
            ->assertOk()
            ->assertJsonPath('data.coachReport.overviewAr', 'يوجد خلط في العلاقة العكسية بين المقاومة وشدة التيار.');
    }
}
