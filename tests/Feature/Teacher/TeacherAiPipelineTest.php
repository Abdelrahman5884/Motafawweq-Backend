<?php

namespace Tests\Feature\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Lesson;
use App\Models\LessonTranscript;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherAiPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(TeacherPlanTier $plan = TeacherPlanTier::PRO): User
    {
        $teacher = User::factory()->create([
            'name' => 'أ. محمد سامي',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id' => $teacher->id,
            'name_ar' => $teacher->name,
        ])->forceFill(['plan_tier' => $plan])->save();

        return $teacher;
    }

    protected function createProStudent(): User
    {
        $student = User::factory()->create([
            'name' => 'طالب متفوق',
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
        ]);

        StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => StudentPlanTier::PRO,
            'plan_name_ar' => 'باقة الطالب برو',
            'plan_name_en' => 'Student Pro',
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => 80,
            'base_amount_egp' => 80,
            'discount_amount_egp' => 0,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
        ]);

        return $student;
    }

    public function test_teacher_full_chained_pipeline_persists_transcript_chapters_and_concept_graph(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('lessons/sample.mp3', 'fake-audio-binary');

        $teacher = $this->createTeacher(TeacherPlanTier::PRO);
        $lesson = Lesson::create([
            'uuid' => (string) Str::uuid(),
            'teacher_id' => $teacher->id,
            'title_ar' => 'البناء الضوئي',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'media_mode' => LessonMediaMode::AUDIO->value,
            'media_disk' => 'local',
            'media_path' => 'lessons/sample.mp3',
            'media_original_name' => 'sample.mp3',
            'duration_seconds' => 180,
            'status' => LessonStatus::PUBLISHED->value,
        ]);

        Http::fake([
            '*/api/v1/stt/jobs' => Http::response([
                'job_id' => 'stt-chain-1',
                'status' => 'queued',
                'progress' => 5,
                'stage' => 'queued',
                'model' => 'mohammedaly22/QwenCleo-ASR',
            ], 202),
            '*/api/v1/stt/jobs/stt-chain-1' => Http::response([
                'job_id' => 'stt-chain-1',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'QwenCleo-ASR',
                    'device' => 'cuda',
                    'compute_type' => 'bfloat16',
                    'detected_language' => 'ar',
                    'detected_language_name_ar' => 'العربية (مصري + EN)',
                    'detected_language_name_en' => 'Arabic (Egyptian + EN)',
                    'language_probability' => 0.99,
                    'duration_seconds' => 180,
                    'processing_seconds' => 4.2,
                    'full_text' => 'أهلاً بكم النهاردة هنشرح البناء الضوئي في الـ Chloroplast.',
                    'segments' => [
                        [
                            'start_seconds' => 0,
                            'end_seconds' => 45,
                            'timestamp' => '00:00',
                            'speaker' => $teacher->name,
                            'text' => 'أهلاً بكم النهاردة هنشرح Photosynthesis البناء الضوئي.',
                        ],
                        [
                            'start_seconds' => 50,
                            'end_seconds' => 140,
                            'timestamp' => '00:50',
                            'speaker' => $teacher->name,
                            'text' => 'التفاعلات الضوئية في الثايلاكويد تنتج ATP و NADPH.',
                        ],
                    ],
                ],
            ], 200),
            '*/api/v1/analysis/jobs' => Http::response([
                'job_id' => 'llm-chain-1',
                'status' => 'queued',
                'progress' => 6,
                'stage' => 'queued',
                'model' => 'Qwen/Qwen2.5-3B-Instruct',
                'tasks' => ['chapters', 'concept_graph'],
            ], 202),
            '*/api/v1/analysis/jobs/llm-chain-1' => Http::response([
                'job_id' => 'llm-chain-1',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'Qwen/Qwen2.5-3B-Instruct',
                    'device' => 'cuda',
                    'quantization' => 'nf4-4bit',
                    'language' => 'ar',
                    'processing_seconds' => 6.1,
                    'chapters' => [
                        ['id' => 'ch-1', 'title_ar' => 'مقدمة البناء الضوئي', 'title_en' => 'Photosynthesis Intro', 'start_seconds' => 0, 'timestamp' => '00:00'],
                        ['id' => 'ch-2', 'title_ar' => 'التفاعلات الضوئية ATP', 'title_en' => 'Light Reactions', 'start_seconds' => 50, 'timestamp' => '00:50'],
                    ],
                    'concept_graph' => [
                        'id' => 'root-1',
                        'label' => 'البناء الضوئي',
                        'labelAr' => 'البناء الضوئي',
                        'labelEn' => 'Photosynthesis',
                        'summaryAr' => 'تحويل الطاقة الضوئية إلى طاقة كيميائية',
                        'startSeconds' => 0,
                        'timestamp' => '00:00',
                        'children' => [
                            [
                                'id' => 'node-2',
                                'label' => 'التفاعلات الضوئية',
                                'labelAr' => 'التفاعلات الضوئية',
                                'labelEn' => 'Light Reactions',
                                'summaryAr' => 'إنتاج ATP و NADPH داخل الثايلاكويد',
                                'startSeconds' => 50,
                                'timestamp' => '00:50',
                                'children' => [],
                            ],
                        ],
                    ],
                    'concepts_count' => 2,
                    'quiz_questions' => [],
                ],
            ], 200),
            '*' => Http::response([], 204),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Step 1: Launch unified pipeline with ALL 3 features selected
        $startRes = $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/process", [
            'features' => [
                'speechToText' => true,
                'chapterIndexing' => true,
                'conceptGraph' => true,
            ],
            'language' => 'auto',
            'force' => true,
        ]);

        $startRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.overallStatus', 'completed')
            ->assertJsonPath('data.overallProgress', 100)
            ->assertJsonPath('data.transcriptCount', 2)
            ->assertJsonPath('data.chaptersCount', 2)
            ->assertJsonPath('data.conceptsCount', 2)
            ->assertJsonPath('data.notebookMindMap.labelAr', 'البناء الضوئي');

        $this->assertDatabaseHas('lesson_chapters', [
            'lesson_id' => $lesson->id,
            'title_ar' => 'التفاعلات الضوئية ATP',
            'start_seconds' => 50,
        ]);

        $this->assertDatabaseHas('lesson_concept_graphs', [
            'lesson_id' => $lesson->id,
            'source' => 'ai',
        ]);
    }

    public function test_teacher_standalone_chapters_and_concept_graph_endpoints(): void
    {
        $teacher = $this->createTeacher(TeacherPlanTier::PRO);
        $lesson = Lesson::create([
            'uuid' => (string) Str::uuid(),
            'teacher_id' => $teacher->id,
            'title_ar' => 'قوانين نيوتن',
            'media_mode' => LessonMediaMode::AUDIO->value,
            'media_disk' => 'local',
            'status' => LessonStatus::PUBLISHED->value,
        ]);

        LessonTranscript::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'speaker' => $teacher->name,
            'start_seconds' => 0,
            'timestamp_label' => '00:00',
            'text_ar' => 'القانون الأول لنيوتن يصف القصور الذاتي.',
            'text_en' => 'Newton first law describes inertia.',
            'display_order' => 1,
        ]);

        Http::fake([
            '*/api/v1/analysis/jobs' => Http::response([
                'job_id' => 'llm-solo-1',
                'status' => 'queued',
                'progress' => 8,
                'stage' => 'queued',
                'model' => 'Qwen/Qwen2.5-3B-Instruct',
                'tasks' => ['chapters'],
            ], 202),
            '*/api/v1/analysis/jobs/llm-solo-1' => Http::response([
                'job_id' => 'llm-solo-1',
                'status' => 'processing',
                'progress' => 45,
                'stage' => 'generating_chapters',
                'tasks' => ['chapters'],
            ], 200),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $res = $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/chapters");
        $res->assertStatus(202)
            ->assertJsonPath('data.activePhase', 'analysis')
            ->assertJsonPath('data.analysisJob.status', 'processing');
    }

    public function test_free_teacher_cannot_access_ai_pipeline_endpoints(): void
    {
        $freeTeacher = $this->createTeacher(TeacherPlanTier::FREE);
        $lesson = Lesson::create([
            'uuid' => (string) Str::uuid(),
            'teacher_id' => $freeTeacher->id,
            'title_ar' => 'حصة مجانية',
            'media_mode' => LessonMediaMode::AUDIO->value,
            'media_disk' => 'local',
            'status' => LessonStatus::PUBLISHED->value,
        ]);

        Sanctum::actingAs($freeTeacher, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/process", [
            'features' => ['chapterIndexing' => true],
        ])->assertStatus(403);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/chapters")
            ->assertStatus(403);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/concept-graph")
            ->assertStatus(403);
    }

    public function test_student_smart_lecture_chains_stt_into_mindmap_topics_and_quiz(): void
    {
        $student = $this->createProStudent();

        Http::fake([
            '*/api/v1/stt/jobs' => Http::response([
                'job_id' => 'std-stt-1',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'QwenCleo-ASR',
            ], 202),
            '*/api/v1/stt/jobs/std-stt-1' => Http::response([
                'job_id' => 'std-stt-1',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'QwenCleo-ASR',
                    'device' => 'cuda',
                    'compute_type' => 'bfloat16',
                    'detected_language' => 'ar',
                    'detected_language_name_ar' => 'العربية (مصري + EN)',
                    'detected_language_name_en' => 'Arabic (Egyptian + EN)',
                    'language_probability' => 0.98,
                    'duration_seconds' => 120,
                    'processing_seconds' => 3.5,
                    'full_text' => 'النهاردة بنراجع الكيمياء العضوية والـ Hydrocarbons.',
                    'segments' => [
                        [
                            'start_seconds' => 0,
                            'end_seconds' => 60,
                            'timestamp' => '00:00',
                            'speaker' => 'المحاضر',
                            'text' => 'النهاردة بنراجع الكيمياء العضوية والـ Hydrocarbons.',
                        ],
                    ],
                ],
            ], 200),
            '*/api/v1/analysis/jobs' => Http::response([
                'job_id' => 'std-llm-1',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'Qwen/Qwen2.5-3B-Instruct',
                'tasks' => ['chapters', 'concept_graph', 'quiz', 'summary'],
            ], 202),
            '*/api/v1/analysis/jobs/std-llm-1' => Http::response([
                'job_id' => 'std-llm-1',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'result' => [
                    'model' => 'Qwen/Qwen2.5-3B-Instruct',
                    'device' => 'cuda',
                    'quantization' => 'nf4-4bit',
                    'language' => 'ar',
                    'processing_seconds' => 5.0,
                    'summary_ar' => 'مراجعة شاملة للكيمياء العضوية والهيدروكربونات.',
                    'chapters' => [
                        ['id' => 'ch-1', 'title_ar' => 'مقدمة الكيمياء العضوية', 'title_en' => 'Organic Chem Intro', 'start_seconds' => 0, 'timestamp' => '00:00'],
                    ],
                    'concept_graph' => [
                        'id' => 'root-1',
                        'label' => 'الكيمياء العضوية',
                        'labelAr' => 'الكيمياء العضوية',
                        'labelEn' => 'Organic Chemistry',
                        'startSeconds' => 0,
                        'timestamp' => '00:00',
                        'children' => [
                            ['id' => 'node-2', 'label' => 'Hydrocarbons', 'labelAr' => 'الهيدروكربونات', 'labelEn' => 'Hydrocarbons', 'startSeconds' => 0, 'timestamp' => '00:00', 'children' => []],
                        ],
                    ],
                    'concepts_count' => 2,
                    'quiz_questions' => [
                        [
                            'id' => 'ai-q-1',
                            'questionAr' => 'ما العناصر الأساسية في الهيدروكربونات؟',
                            'questionEn' => 'What are the core elements in Hydrocarbons?',
                            'optionsAr' => ['الكربون والهيدروجين', 'الحديد والأكسجين', 'الصوديوم والكلور', 'النيتروجين والهيليوم'],
                            'optionsEn' => ['Carbon and Hydrogen', 'Iron and Oxygen', 'Sodium and Chlorine', 'Nitrogen and Helium'],
                            'correctIndex' => 0,
                            'explanationAr' => 'الهيدروكربونات تتكون أساساً من الكربون والهيدروجين.',
                            'explanationEn' => 'Hydrocarbons consist primarily of Carbon and Hydrogen.',
                        ],
                    ],
                ],
            ], 200),
            '*' => Http::response([], 204),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $audio = UploadedFile::fake()->create('organic.mp3', 200, 'audio/mpeg');
        $createRes = $this->post('/api/v1/student/converted-lectures', [
            'title_ar' => 'الكيمياء العضوية',
            'outputs' => ['graph', 'transcript', 'topics', 'quiz'],
            'file' => $audio,
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'std-ai-pipe-1']);

        $createRes->assertStatus(201);
        $uuid = $createRes->json('data.id');
        $this->assertNotEmpty($uuid);

        // Poll status -> completes STT, chains LLM Analysis, persists all 4 outputs!
        $statusRes = $this->getJson("/api/v1/student/converted-lectures/{$uuid}/status");
        $statusRes->assertStatus(200)
            ->assertJsonPath('data.overallStatus', 'completed')
            ->assertJsonPath('data.conceptsCount', 2)
            ->assertJsonPath('data.questionsCount', 1)
            ->assertJsonPath('data.mindmap.labelAr', 'الكيمياء العضوية')
            ->assertJsonPath('data.topics.0.titleAr', 'مقدمة الكيمياء العضوية')
            ->assertJsonPath('data.quizQuestions.0.correctIndex', 0);

        // On-demand AI Quiz regeneration
        $quizRes = $this->postJson("/api/v1/student/converted-lectures/{$uuid}/quiz", [
            'quiz_count' => 5,
        ]);
        $quizRes->assertStatus(202)
            ->assertJsonPath('success', true);
    }
}
