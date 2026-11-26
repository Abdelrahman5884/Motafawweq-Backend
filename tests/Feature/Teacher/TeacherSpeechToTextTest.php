<?php

namespace Tests\Feature\Teacher;

use App\Enums\BillingPeriod;
use App\Enums\CourseStatus;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Enums\StudentPlanTier;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AiProcessingJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherSpeechToTextTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(
        string $email = 'pro.teacher@motafawweq.com',
        TeacherPlanTier $plan = TeacherPlanTier::PRO
    ): User {
        $teacher = User::factory()->create([
            'email' => $email,
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

    protected function createLessonWithAudio(User $teacher, bool $withMedia = true): Lesson
    {
        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الأحياء للثانوية العامة',
            'stage_id' => 'sec',
            'stage_name_ar' => 'المرحلة الثانوية',
            'grade_ar' => 'الصف الثالث الثانوي',
            'subject_ar' => 'الأحياء',
            'price_egp' => 250,
            'status' => CourseStatus::PUBLISHED->value,
        ]);

        $mediaPath = null;
        if ($withMedia) {
            Storage::fake('local');
            $mediaPath = "lessons/audio/{$teacher->id}_sample.mp3";
            Storage::disk('local')->put($mediaPath, 'fake-mp3-audio-bytes-for-test');
        }

        return Lesson::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'الدعامة في النبات',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'media_mode' => LessonMediaMode::AUDIO->value,
            'media_disk' => 'local',
            'media_path' => $mediaPath,
            'media_original_name' => $withMedia ? 'lecture1.mp3' : null,
            'media_mime_type' => $withMedia ? 'audio/mpeg' : null,
            'duration_seconds' => 0,
            'duration_label' => '00:00',
            'status' => LessonStatus::PUBLISHED->value,
            'published_at' => now(),
        ]);
    }

    public function test_pro_teacher_can_run_full_multilingual_stt_pipeline_and_persist_transcript(): void
    {
        $teacher = $this->createTeacher();
        $lesson = $this->createLessonWithAudio($teacher, true);

        Http::fake([
            '*/api/v1/stt/jobs' => Http::response([
                'job_id' => 'remote-job-abc123',
                'status' => 'queued',
                'progress' => 5,
                'stage' => 'queued',
                'model' => 'large-v3',
                'segments_processed' => 0,
            ], 202),
            '*/api/v1/stt/jobs/remote-job-abc123' => Http::sequence()
                ->push([
                    'job_id' => 'remote-job-abc123',
                    'status' => 'processing',
                    'progress' => 52,
                    'stage' => 'transcribing',
                    'model' => 'large-v3',
                    'detected_language' => 'ar',
                    'detected_language_name_ar' => 'العربية',
                    'detected_language_name_en' => 'Arabic',
                    'language_probability' => 0.986,
                    'duration_seconds' => 145,
                    'segments_processed' => 4,
                ], 200)
                ->push([
                    'job_id' => 'remote-job-abc123',
                    'status' => 'completed',
                    'progress' => 100,
                    'stage' => 'completed',
                    'model' => 'large-v3',
                    'detected_language' => 'ar',
                    'detected_language_name_ar' => 'العربية',
                    'detected_language_name_en' => 'Arabic',
                    'language_probability' => 0.986,
                    'duration_seconds' => 145,
                    'segments_processed' => 2,
                    'result' => [
                        'model' => 'large-v3',
                        'device' => 'cuda',
                        'compute_type' => 'int8_float16',
                        'detected_language' => 'ar',
                        'detected_language_name_ar' => 'العربية',
                        'detected_language_name_en' => 'Arabic',
                        'language_probability' => 0.986,
                        'duration_seconds' => 145,
                        'processing_seconds' => 4.2,
                        'full_text' => 'النهاردة هنشرح الدعامة الفسيولوجية وامتصاص الماء بالخاصية الأسموزية.',
                        'segments' => [
                            [
                                'start_seconds' => 0,
                                'end_seconds' => 18,
                                'start_exact' => 0.0,
                                'end_exact' => 18.2,
                                'timestamp' => '00:00',
                                'speaker' => 'أ. محمد سامي',
                                'text' => 'أهلاً بيكم يا شباب، النهاردة هنشرح الدعامة الفسيولوجية في النبات.',
                                'language' => 'ar',
                            ],
                            [
                                'start_seconds' => 19,
                                'end_seconds' => 42,
                                'start_exact' => 19.0,
                                'end_exact' => 42.1,
                                'timestamp' => '00:19',
                                'speaker' => 'أ. محمد سامي',
                                'text' => 'عملية الـ Osmosis أو الخاصية الأسموزية بتعتمد على تركيز المحلول داخل الفجوة العصارية.',
                                'language' => 'ar',
                            ],
                        ],
                    ],
                ], 200),
            '*' => Http::response(null, 204),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // 1. Trigger transcription
        $startRes = $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/transcribe", [
            'language' => 'auto',
        ]);

        $startRes->assertStatus(202)
            ->assertJsonPath('data.job.status', 'queued')
            ->assertJsonPath('data.job.model', 'large-v3');

        // 2. First poll -> processing with detected language
        $poll1 = $this->getJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/status");
        $poll1->assertOk()
            ->assertJsonPath('data.job.status', 'processing')
            ->assertJsonPath('data.job.progress', 52)
            ->assertJsonPath('data.job.detectedLanguage', 'ar');

        // 3. Second poll -> completed & segments persisted to lesson_transcripts
        $poll2 = $this->getJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/status");
        $poll2->assertOk()
            ->assertJsonPath('data.job.status', 'completed')
            ->assertJsonPath('data.job.progress', 100)
            ->assertJsonPath('data.job.detectedLanguageNameAr', 'العربية')
            ->assertJsonPath('data.transcriptCount', 2)
            ->assertJsonPath('data.durationSeconds', 145)
            ->assertJsonPath('data.durationFormatted', '02:25')
            ->assertJsonPath('data.transcript.0.timestamp', '00:00')
            ->assertJsonPath('data.transcript.1.startSeconds', 19);

        $this->assertDatabaseCount('lesson_transcripts', 2);
        $this->assertDatabaseHas('ai_processing_jobs', [
            'lesson_id' => $lesson->id,
            'status' => AiProcessingJob::STATUS_COMPLETED,
            'detected_language' => 'ar',
            'segments_count' => 2,
        ]);
    }

    public function test_non_pro_teacher_is_blocked_from_ai_transcription(): void
    {
        $freeTeacher = $this->createTeacher('free@motafawweq.com', TeacherPlanTier::FREE);
        $lesson = $this->createLessonWithAudio($freeTeacher, true);

        Sanctum::actingAs($freeTeacher, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/transcribe")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_teacher_cannot_transcribe_another_teachers_lesson(): void
    {
        $teacherA = $this->createTeacher('owner@motafawweq.com');
        $teacherB = $this->createTeacher('intruder@motafawweq.com');
        $lessonA = $this->createLessonWithAudio($teacherA, true);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lessonA->uuid}/ai/transcribe")
            ->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event' => 'teacher.idor_attempt_blocked',
        ]);
    }

    public function test_lesson_without_media_returns_validation_error(): void
    {
        $teacher = $this->createTeacher('nomedia@motafawweq.com');
        $lesson = $this->createLessonWithAudio($teacher, false);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/transcribe")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media']);
    }

    public function test_unreachable_ai_service_returns_friendly_503(): void
    {
        $teacher = $this->createTeacher('offline@motafawweq.com');
        $lesson = $this->createLessonWithAudio($teacher, true);

        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->postJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/ai/transcribe")
            ->assertStatus(503)
            ->assertJsonPath('success', false);
    }

    public function test_student_smart_lecture_triggers_real_stt_and_saves_multilingual_transcript(): void
    {
        $student = User::factory()->create([
            'email' => 'pro.student@motafawweq.com',
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'grade_ar' => 'الصف الثالث الثانوي',
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

        Http::fake([
            '*/api/v1/stt/jobs' => Http::response([
                'job_id' => 'std-job-99',
                'status' => 'queued',
                'progress' => 10,
                'stage' => 'queued',
                'model' => 'large-v3',
            ], 202),
            '*/api/v1/stt/jobs/std-job-99' => Http::response([
                'job_id' => 'std-job-99',
                'status' => 'completed',
                'progress' => 100,
                'stage' => 'completed',
                'model' => 'large-v3',
                'detected_language' => 'en',
                'detected_language_name_ar' => 'الإنجليزية',
                'detected_language_name_en' => 'English',
                'language_probability' => 0.994,
                'duration_seconds' => 62,
                'segments_processed' => 1,
                'result' => [
                    'model' => 'large-v3',
                    'device' => 'cuda',
                    'compute_type' => 'int8_float16',
                    'detected_language' => 'en',
                    'detected_language_name_ar' => 'الإنجليزية',
                    'detected_language_name_en' => 'English',
                    'language_probability' => 0.994,
                    'duration_seconds' => 62,
                    'processing_seconds' => 1.8,
                    'full_text' => 'Newton second law states that force equals mass times acceleration.',
                    'segments' => [
                        [
                            'start_seconds' => 0,
                            'end_seconds' => 12,
                            'start_exact' => 0.0,
                            'end_exact' => 12.0,
                            'timestamp' => '00:00',
                            'speaker' => 'المحاضر',
                            'text' => 'Newton second law states that force equals mass times acceleration.',
                            'language' => 'en',
                        ],
                    ],
                ],
            ], 200),
            '*' => Http::response(null, 204),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $audioUpload = UploadedFile::fake()->create('physics_lecture.mp3', 120, 'audio/mpeg');

        $createRes = $this->post('/api/v1/student/converted-lectures', [
            'title_ar' => 'قوانين نيوتن',
            'outputs' => ['transcript'],
            'file' => $audioUpload,
        ], ['Accept' => 'application/json']);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.sttJob.status', 'queued');

        $lectureUuid = $createRes->json('data.id');

        $statusRes = $this->getJson("/api/v1/student/converted-lectures/{$lectureUuid}/status");
        $statusRes->assertOk()
            ->assertJsonPath('data.sttJob.status', 'completed')
            ->assertJsonPath('data.sttJob.detectedLanguage', 'en')
            ->assertJsonPath('data.duration', '01:02')
            ->assertJsonPath('data.transcript.0.textAr', 'Newton second law states that force equals mass times acceleration.');
    }
}
