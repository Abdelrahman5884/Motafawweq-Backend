<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\ExamSubmissionStatus;
use App\Enums\LessonAttachmentType;
use App\Enums\LessonStatus;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonChapter;
use App\Models\LessonConceptGraph;
use App\Models\LessonTranscript;
use App\Models\StudentXpLedger;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLessonWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(): User
    {
        return User::factory()->create([
            'name' => 'د. كريم الأحياء',
            'email' => 'lesson.teacher.'.random_int(1000, 9999).'@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'نور محمود'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'lesson.student.'.random_int(1000, 9999).'@motafawweq.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(100000, 999999),
        ]);
    }

    protected function enrollStudent(User $student, User $teacher, Course $course): void
    {
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'name_ar' => 'مجموعة الأحياء المتقدمة',
            'subject_ar' => 'الأحياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة A',
            'price_egp' => 450,
            'join_code' => 'BIO-'.random_int(1000, 9999),
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

    public function test_lesson_payload_hydrates_chapters_transcript_concept_graph_attachments_and_safe_exams(): void
    {
        Storage::fake('local');

        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الأحياء للثانوية العامة',
            'subject_ar' => 'الأحياء',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'الدعامة في الإنسان',
            'description_ar' => 'شرح الهيكل المحوري والطرفي بالتفصيل',
            'duration_seconds' => 1200,
            'duration_label' => '20:00',
            'status' => LessonStatus::PUBLISHED,
        ]);

        LessonChapter::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'مقدمة الجهاز الهيكلي',
            'start_seconds' => 0,
            'timestamp_label' => '00:00',
            'display_order' => 1,
        ]);
        LessonChapter::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'العمود الفقري والجمجمة',
            'start_seconds' => 420,
            'timestamp_label' => '07:00',
            'display_order' => 2,
        ]);

        LessonTranscript::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'speaker' => 'د. كريم',
            'start_seconds' => 0,
            'timestamp_label' => '00:00',
            'text_ar' => 'أهلاً بكم في درس الدعامة في الإنسان.',
            'display_order' => 1,
        ]);
        LessonTranscript::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'speaker' => 'د. كريم',
            'start_seconds' => 180,
            'timestamp_label' => '03:00',
            'text_ar' => 'يتكون العمود الفقري من 33 فقرة.',
            'display_order' => 2,
        ]);

        LessonConceptGraph::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'source' => 'manual',
            'tree_data' => [
                'id' => 'root',
                'labelAr' => 'الجهاز الهيكلي',
                'summaryAr' => 'يتكون من 206 عظمة',
                'startSeconds' => 0,
                'children' => [
                    [
                        'id' => 'axial',
                        'labelAr' => 'الهيكل المحوري',
                        'summaryAr' => 'الجمجمة والعمود الفقري والقفص الصدري',
                        'startSeconds' => 420,
                    ],
                ],
            ],
        ]);

        Storage::disk('local')->put('lessons/summary.pdf', '%PDF-1.4 test content');
        LessonAttachment::create([
            'lesson_id' => $lesson->id,
            'teacher_id' => $teacher->id,
            'type' => LessonAttachmentType::PDF,
            'title' => 'ملخص الدعامة في الإنسان',
            'original_file_name' => 'ملخص-الدعامة.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'lessons/summary.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 20480,
            'pages_count' => 12,
            'display_order' => 1,
        ]);

        // Unsubmitted active exam: MUST appear in relatedExams but MUST NOT leak questions/answers
        $activeExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'اختبار الدعامة السري',
            'duration_minutes' => 30,
            'full_mark' => 10,
            'pass_score' => 5,
            'questions_count' => 1,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);
        ExamQuestion::create([
            'exam_id' => $activeExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'difficulty' => 'hard',
            'question_ar' => 'سؤال غير محلول لا يجب تسريب إجابته',
            'options_ar' => ['أ', 'ب'],
            'correct_index' => 1,
        ]);

        // Already graded exam with show_results=true: SAFE to surface in practiceQuestions
        $completedExam = Exam::create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'title_ar' => 'كويز تم حله',
            'duration_minutes' => 15,
            'full_mark' => 10,
            'pass_score' => 5,
            'questions_count' => 1,
            'status' => ExamStatus::ACTIVE,
            'show_results' => true,
        ]);
        ExamQuestion::create([
            'exam_id' => $completedExam->id,
            'question_number' => 1,
            'type' => ExamQuestionType::MCQ,
            'points' => 10,
            'difficulty' => 'easy',
            'question_ar' => 'كم عدد فقرات العمود الفقري؟',
            'options_ar' => ['24', '33', '26'],
            'correct_index' => 1,
            'explanation_ar' => '7 عنقية + 12 ظهرية + 5 قطنية + 5 عجزية + 4 عصعصية.',
        ]);
        ExamSubmission::create([
            'exam_id' => $completedExam->id,
            'student_id' => $student->id,
            'status' => ExamSubmissionStatus::GRADED,
            'score' => 10,
            'full_mark' => 10,
            'percentage' => 100,
            'is_passed' => true,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(50),
            'graded_at' => now()->subMinutes(50),
        ]);

        $this->enrollStudent($student, $teacher, $course);
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson("/api/v1/student/lessons/{$lesson->uuid}")->assertOk();

        // Chapters have computed startSeconds/endSeconds/order
        $res->assertJsonPath('data.course.titleAr', 'الأحياء للثانوية العامة')
            ->assertJsonPath('data.chapters.0.order', 1)
            ->assertJsonPath('data.chapters.0.startSeconds', 0)
            ->assertJsonPath('data.chapters.0.endSeconds', 420)
            ->assertJsonPath('data.chapters.1.order', 2)
            ->assertJsonPath('data.chapters.1.startSeconds', 420)
            ->assertJsonPath('data.chapters.1.endSeconds', 1200);

        // Transcript has real speaker and computed endSeconds (never 0)
        $res->assertJsonPath('data.transcript.0.speaker', 'د. كريم')
            ->assertJsonPath('data.transcript.0.startSeconds', 0)
            ->assertJsonPath('data.transcript.0.endSeconds', 180)
            ->assertJsonPath('data.transcript.1.endSeconds', 1200);

        // Concept graph flattened from tree_data into SVG nodes + edges AND full hierarchical notebookMindMap
        $res->assertJsonCount(2, 'data.conceptGraph.nodes')
            ->assertJsonCount(1, 'data.conceptGraph.edges')
            ->assertJsonPath('data.conceptGraph.nodes.0.label', 'الجهاز الهيكلي')
            ->assertJsonPath('data.conceptGraph.edges.0.from', 'root')
            ->assertJsonPath('data.conceptGraph.edges.0.to', 'axial')
            ->assertJsonPath('data.notebookMindMap.labelAr', 'الجهاز الهيكلي')
            ->assertJsonPath('data.notebookMindMap.children.0.labelAr', 'الهيكل المحوري');

        // Related exams include both, while practiceQuestions ONLY include the already-graded exam (never leaks unsubmitted!)
        $res->assertJsonCount(2, 'data.relatedExams')
            ->assertJsonCount(1, 'data.practiceQuestions')
            ->assertJsonPath('data.practiceQuestions.0.q', 'كم عدد فقرات العمود الفقري؟')
            ->assertJsonPath('data.practiceQuestions.0.diff', 'سهل');

        // Signed attachment download URL returns attachment disposition with UTF-8 filename
        $res->assertJsonPath('data.attachments.0.titleAr', 'ملخص الدعامة في الإنسان')
            ->assertJsonPath('data.attachments.0.pages', '12 صفحة');

        $downloadUrl = $res->json('data.attachments.0.downloadUrl');
        $this->assertNotEmpty($downloadUrl);
        $dlRes = $this->get($downloadUrl);
        $dlRes->assertOk();
        $this->assertStringContainsString('attachment;', (string) $dlRes->headers->get('Content-Disposition'));
    }

    public function test_toggle_complete_persists_state_and_prevents_xp_farming_and_notes_crud_enforces_idor(): void
    {
        $teacher = $this->createTeacher();
        $studentA = $this->createStudent('طالب أ');
        $studentB = $this->createStudent('طالب ب');

        $course = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس الفيزياء',
            'stage' => 'high',
            'grade_ar' => 'الصف الثالث الثانوي',
            'status' => CourseStatus::PUBLISHED,
        ]);

        $lesson = Lesson::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'title_ar' => 'قانون أوم للدائرة المغلقة',
            'duration_seconds' => 45, // short lesson (<60s)
            'duration_label' => '00:45',
            'status' => LessonStatus::PUBLISHED,
        ]);

        $this->enrollStudent($studentA, $teacher, $course);
        $this->enrollStudent($studentB, $teacher, $course);

        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);

        // Short lesson heartbeat (<60s duration) works cleanly
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/progress", [
            'position_seconds' => 15,
            'watched_delta_seconds' => 15,
            'duration_seconds' => 45,
        ])->assertOk()
            ->assertJsonPath('data.watchedSeconds', 15);

        // 1. Mark complete -> persists isCompleted=true & awards 25 XP
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/toggle-complete", [
            'is_completed' => true,
        ])->assertOk()
            ->assertJsonPath('data.isCompleted', true)
            ->assertJsonPath('data.progressPercent', 100)
            ->assertJsonPath('data.xpAwarded', 25);

        $this->assertEquals(25, StudentXpLedger::forStudent($studentA->id)->sum('points'));

        // 2. Revert completion -> persists isCompleted=false
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/toggle-complete", [
            'is_completed' => false,
        ])->assertOk()
            ->assertJsonPath('data.isCompleted', false)
            ->assertJsonPath('data.xpAwarded', 0);

        // 3. Re-complete -> persists isCompleted=true but awards 0 duplicate XP (anti-XP-farming!)
        $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/toggle-complete", [
            'is_completed' => true,
        ])->assertOk()
            ->assertJsonPath('data.isCompleted', true)
            ->assertJsonPath('data.xpAwarded', 0);

        $this->assertEquals(25, StudentXpLedger::forStudent($studentA->id)->sum('points'));

        // 4. Lesson notes full CRUD (create, update, IDOR block, delete)
        $noteRes = $this->postJson("/api/v1/student/lessons/{$lesson->uuid}/notes", [
            'timestamp_seconds' => 20,
            'content' => '<b>ملاحظة أولى</b> عن المقاومة الداخلية',
        ])->assertCreated()
            ->assertJsonPath('data.content', 'ملاحظة أولى عن المقاومة الداخلية');

        $noteUuid = $noteRes->json('data.id');

        $this->putJson("/api/v1/student/lessons/notes/{$noteUuid}", [
            'timestamp_seconds' => 30,
            'content' => 'ملاحظة محدّثة بدقة',
        ])->assertOk()
            ->assertJsonPath('data.content', 'ملاحظة محدّثة بدقة')
            ->assertJsonPath('data.timestampSeconds', 30);

        // Cross-student IDOR blocked on update & delete
        Sanctum::actingAs($studentB, [TokenType::ACCESS->value]);
        $this->putJson("/api/v1/student/lessons/notes/{$noteUuid}", [
            'content' => 'محاولة اختراق',
        ])->assertForbidden();
        $this->deleteJson("/api/v1/student/lessons/notes/{$noteUuid}")->assertForbidden();

        // Owner deletes cleanly
        Sanctum::actingAs($studentA, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/student/lessons/notes/{$noteUuid}")->assertOk();
    }
}
