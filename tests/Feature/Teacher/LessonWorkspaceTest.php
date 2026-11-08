<?php

namespace Tests\Feature\Teacher;

use App\Enums\CourseStatus;
use App\Enums\LessonMediaMode;
use App\Enums\LessonStatus;
use App\Enums\StageType;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $email = 'teacher@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createCourse(User $teacher, string $title = 'الفيزياء الحديثة'): Course
    {
        return Course::create([
            'teacher_id'    => $teacher->id,
            'title_ar'      => $title,
            'stage_id'      => 'sec',
            'stage_name_ar' => 'المرحلة الثانوية',
            'grade_ar'      => 'الصف الثالث الثانوي',
            'subject_ar'    => 'الفيزياء',
            'price_egp'     => 300,
            'status'        => CourseStatus::PUBLISHED->value,
        ]);
    }

    protected function createLesson(
        User $teacher,
        Course $course,
        string $title = 'مقدمة الفيزياء الكمية',
        string $mediaMode = 'video',
        int $durationSeconds = 3600
    ): Lesson {
        return Lesson::create([
            'teacher_id'       => $teacher->id,
            'course_id'        => $course->id,
            'title_ar'         => $title,
            'subject_ar'       => 'الفيزياء',
            'grade_ar'         => 'الصف الثالث الثانوي',
            'media_mode'       => $mediaMode,
            'duration_seconds' => $durationSeconds,
            'duration_label'   => '60:00',
            'status'           => LessonStatus::PUBLISHED->value,
            'published_at'     => now(),
        ]);
    }

    public function test_teacher_can_fetch_workspace_overview_and_kpis(): void
    {
        $teacher = $this->createTeacher();
        $course = $this->createCourse($teacher);

        $this->createLesson($teacher, $course, 'محاضرة فيديو 1', LessonMediaMode::VIDEO->value, 3600);
        $this->createLesson($teacher, $course, 'محاضرة صوتية 2', LessonMediaMode::AUDIO->value, 1800);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson('/api/v1/teacher/workspace');

        $response->assertOk()
            ->assertJsonPath('data.stats.totalLessons', 2)
            ->assertJsonPath('data.stats.totalHours', 1.5)
            ->assertJsonPath('data.stats.videoCount', 1)
            ->assertJsonPath('data.stats.audioCount', 1)
            ->assertJsonCount(2, 'data.lessons');
    }

    public function test_teacher_can_sync_chapters_sorted_by_start_seconds(): void
    {
        $teacher = $this->createTeacher();
        $course = $this->createCourse($teacher);
        $lesson = $this->createLesson($teacher, $course);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->putJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/chapters", [
            'chapters' => [
                [
                    'titleAr'      => 'الفصل الثاني: إشعاع الجسم الأسود',
                    'titleEn'      => 'Chapter 2: Blackbody Radiation',
                    'startSeconds' => 300,
                ],
                [
                    'titleAr'      => 'الفصل الأول: مقدمة تاريخية',
                    'titleEn'      => 'Chapter 1: Historical Intro',
                    'startSeconds' => 0,
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.titleAr', 'الفصل الأول: مقدمة تاريخية')
            ->assertJsonPath('data.0.startSeconds', 0)
            ->assertJsonPath('data.0.timestamp', '00:00')
            ->assertJsonPath('data.1.titleAr', 'الفصل الثاني: إشعاع الجسم الأسود')
            ->assertJsonPath('data.1.startSeconds', 300)
            ->assertJsonPath('data.1.timestamp', '05:00');
    }

    public function test_teacher_can_sync_transcript_segments(): void
    {
        $teacher = $this->createTeacher();
        $course = $this->createCourse($teacher);
        $lesson = $this->createLesson($teacher, $course);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->putJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/transcript", [
            'segments' => [
                [
                    'speaker'      => 'د. أحمد زويل',
                    'startSeconds' => 65,
                    'textAr'       => 'لاحظ ماكس بلانك أن الطاقة مكمأة وليست متصلة.',
                    'textEn'       => 'Max Planck noticed that energy is quantized, not continuous.',
                ],
                [
                    'speaker'      => 'د. أحمد زويل',
                    'startSeconds' => 0,
                    'textAr'       => 'أهلاً بكم في محاضرة الفيزياء الحديثة.',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.startSeconds', 0)
            ->assertJsonPath('data.0.timestamp', '00:00')
            ->assertJsonPath('data.1.startSeconds', 65)
            ->assertJsonPath('data.1.timestamp', '01:05')
            ->assertJsonPath('data.1.speaker', 'د. أحمد زويل');
    }

    public function test_teacher_can_save_concept_graph_and_fetch_complete_lesson_workspace(): void
    {
        $teacher = $this->createTeacher();
        $course = $this->createCourse($teacher);
        $lesson = $this->createLesson($teacher, $course);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $treeData = [
            'id'           => 'root-1',
            'labelAr'      => 'الفيزياء الكمية',
            'labelEn'      => 'Quantum Physics',
            'summaryAr'    => 'فرع الفيزياء الذي يدرس الأنظمة الذرية ودون الذرية',
            'startSeconds' => 0,
            'children'     => [
                [
                    'id'           => 'child-1',
                    'labelAr'      => 'تكمية الطاقة (بلانك)',
                    'labelEn'      => 'Energy Quantization',
                    'startSeconds' => 120,
                    'children'     => [],
                ],
            ],
        ];

        $saveResponse = $this->putJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/concept-graph", [
            'tree_data' => $treeData,
            'source'    => 'manual',
        ]);

        $saveResponse->assertOk()
            ->assertJsonPath('data.labelAr', 'الفيزياء الكمية')
            ->assertJsonPath('data.children.0.labelAr', 'تكمية الطاقة (بلانك)')
            ->assertJsonPath('data.children.0.timestamp', '02:00');

        // Verify GET /api/v1/teacher/workspace/lessons/{uuid} returns the full workspace including mind map
        $showResponse = $this->getJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}");

        $showResponse->assertOk()
            ->assertJsonPath('data.id', $lesson->uuid)
            ->assertJsonPath('data.notebookMindMap.labelAr', 'الفيزياء الكمية')
            ->assertJsonPath('data.notebookMindMap.children.0.id', 'child-1');
    }

    public function test_concept_graph_rejects_excessive_recursion_depth_anti_json_bomb(): void
    {
        $teacher = $this->createTeacher();
        $course = $this->createCourse($teacher);
        $lesson = $this->createLesson($teacher, $course);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        // Build a 8-level deep nested tree (exceeds MAX_GRAPH_DEPTH = 6)
        $deepTree = ['labelAr' => 'Level 8', 'children' => []];
        for ($level = 7; $level >= 1; $level--) {
            $deepTree = [
                'labelAr'  => "Level {$level}",
                'children' => [$deepTree],
            ];
        }

        $response = $this->putJson("/api/v1/teacher/workspace/lessons/{$lesson->uuid}/concept-graph", [
            'tree_data' => $deepTree,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tree_data']);
    }

    public function test_teacher_cannot_modify_another_teachers_lesson_workspace_and_idor_is_logged(): void
    {
        $teacherA = $this->createTeacher('teacherA@motafawweq.com');
        $teacherB = $this->createTeacher('teacherB@motafawweq.com');

        $courseA = $this->createCourse($teacherA);
        $lessonA = $this->createLesson($teacherA, $courseA);

        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->putJson("/api/v1/teacher/workspace/lessons/{$lessonA->uuid}/chapters", [
            'chapters' => [
                ['titleAr' => 'اختراق', 'startSeconds' => 0],
            ],
        ]);

        $response->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}
