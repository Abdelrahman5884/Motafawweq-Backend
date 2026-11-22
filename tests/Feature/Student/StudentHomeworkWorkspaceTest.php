<?php

namespace Tests\Feature\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\GroupEnrollment;
use App\Models\Homework;
use App\Models\StudyGroup;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentHomeworkWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createTeacher(string $email = 'teacher.shw@motafawweq.com'): User
    {
        $teacher = User::factory()->create([
            'name' => 'أ. سامح عبد الرحمن',
            'email' => $email,
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        TeacherProfile::create([
            'user_id' => $teacher->id,
            'name_ar' => $teacher->name,
        ])->forceFill(['plan_tier' => TeacherPlanTier::PLUS])->save();

        return $teacher;
    }

    private function createStudent(string $email = 'student.shw@motafawweq.com'): User
    {
        return User::factory()->create([
            'name' => 'أحمد محمود',
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function enrollStudent(User $student, User $teacher): StudyGroup
    {
        $group = StudyGroup::firstOrCreate(
            ['teacher_id' => $teacher->id, 'join_code' => 'SHW-'.$teacher->id],
            [
                'name_ar' => 'مجموعة الفيزياء',
                'subject_ar' => 'الفيزياء',
                'grade_ar' => 'الصف الثالث الثانوي',
                'center_name' => 'سنتر النخبة',
                'status' => 'active',
            ]
        );

        GroupEnrollment::firstOrCreate(
            ['study_group_id' => $group->id, 'student_id' => $student->id],
            ['status' => EnrollmentStatus::ENROLLED, 'enrolled_at' => now()]
        );

        return $group;
    }

    public function test_student_can_list_homeworks_stream_teacher_attachment_submit_and_update(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();
        $otherStudent = $this->createStudent('outsider.shw@motafawweq.com');
        $this->enrollStudent($student, $teacher);

        $teacherPdfPath = "homeworks/{$teacher->id}/teacher_attachments/sheet.pdf";
        Storage::disk('local')->put($teacherPdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'course_title_ar' => 'الفيزياء الحديثة',
            'lesson_title_ar' => 'قانون أوم للدائرة المغلقة',
            'title_ar' => 'واجب الحصة الأولى',
            'type' => HomeworkType::FILE,
            'file_type' => 'pdf',
            'attachment_name' => 'شيت واجب الفيزياء.pdf',
            'attachment_path' => $teacherPdfPath,
            'text_content' => 'قم بحل المسائل من 1 إلى 10 بخطوات واضحة.',
            'due_date' => now()->addDays(2)->toDateString(),
            'due_time' => '23:59',
            'max_grade' => 25,
        ]);

        // 1. Enrolled student lists homeworks and gets instructions + signed teacher attachment URL
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $listRes = $this->getJson('/api/v1/student/homeworks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $hw->uuid)
            ->assertJsonPath('data.0.textContent', 'قم بحل المسائل من 1 إلى 10 بخطوات واضحة.')
            ->assertJsonPath('data.0.attachmentName', 'شيت واجب الفيزياء.pdf')
            ->assertJsonPath('data.0.submissionStatus', 'not_submitted')
            ->assertJsonPath('data.0.isOverdue', false);

        $signedTeacherUrl = $listRes->json('data.0.attachmentUrl');
        $this->assertNotEmpty($signedTeacherUrl);

        // 2. Signed URL streams teacher PDF; unsigned/tampered URL is blocked (403)
        $this->get($signedTeacherUrl)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get("/api/v1/media/student-homeworks/{$hw->uuid}/attachment/stream")
            ->assertForbidden();

        // Authenticated download works for enrolled student and blocks unenrolled outsider (403)
        $this->get("/api/v1/student/homeworks/{$hw->uuid}/attachment")->assertOk();

        Sanctum::actingAs($otherStudent, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/homeworks/{$hw->uuid}/attachment")->assertForbidden();

        // 3. Enrolled student submits text + PDF solution
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $solutionPdf = UploadedFile::fake()->createWithContent(
            'حل_الطالب.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );

        $submitRes = $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'تم حل جميع المسائل في الملف المرفق.',
            'file' => $solutionPdf,
        ])->assertCreated()
            ->assertJsonPath('data.status', HomeworkSubmissionStatus::PENDING->value)
            ->assertJsonPath('data.submissionType', 'pdf')
            ->assertJsonPath('data.attachmentName', 'حل_الطالب.pdf');

        $submissionUuid = $submitRes->json('data.id');
        $signedSubUrl = $submitRes->json('data.attachmentUrl');
        $this->assertNotEmpty($signedSubUrl);

        // Student can stream own submission via signed URL & authenticated endpoint
        $this->get($signedSubUrl)->assertOk();
        $this->get("/api/v1/student/homeworks/submissions/{$submissionUuid}/attachment")->assertOk();

        // Outsider cannot access student's submission attachment (IDOR 403)
        Sanctum::actingAs($otherStudent, [TokenType::ACCESS->value]);
        $this->getJson("/api/v1/student/homeworks/submissions/{$submissionUuid}/attachment")->assertForbidden();

        // 4. Student updates solution text without uploading a new file -> existing PDF is preserved!
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'تعديل الحل النصي مع الاحتفاظ بالملف.',
        ])->assertCreated()
            ->assertJsonPath('data.submissionType', 'pdf')
            ->assertJsonPath('data.attachmentName', 'حل_الطالب.pdf')
            ->assertJsonPath('data.solutionText', 'تعديل الحل النصي مع الاحتفاظ بالملف.');

        // 5. Student explicitly removes attachment while keeping text -> switches cleanly to text-only
        $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'حل نصي فقط بعد إزالة الملف.',
            'remove_attachment' => true,
        ])->assertCreated()
            ->assertJsonPath('data.submissionType', 'text')
            ->assertJsonPath('data.attachmentName', null)
            ->assertJsonPath('data.attachmentUrl', null);
    }

    public function test_rejected_homework_shows_feedback_and_resets_on_resubmit_and_blocks_graded_edits(): void
    {
        $teacher = $this->createTeacher('teacher.rej@motafawweq.com');
        $student = $this->createStudent('student.rej@motafawweq.com');
        $this->enrollStudent($student, $teacher);

        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'course_title_ar' => 'الكيمياء العضوية',
            'title_ar' => 'واجب الألكانات',
            'type' => HomeworkType::TEXT,
            'text_content' => 'اكتب معادلات تحضير الميثان.',
            'due_date' => now()->subDay()->toDateString(),
            'due_time' => '18:00',
            'max_grade' => 20,
        ]);

        // 1. Overdue flag is true when due_date is past and not submitted
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/homeworks')
            ->assertOk()
            ->assertJsonPath('data.0.isOverdue', true);

        // 2. Submit initial solution
        $subRes = $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'إجابة أولية ناقصة',
        ])->assertCreated();
        $submissionUuid = $subRes->json('data.id');

        // 3. Teacher rejects submission with feedback
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->patchJson("/api/v1/teacher/homeworks/{$hw->uuid}/submissions/{$submissionUuid}/grade", [
            'status' => 'rejected',
            'feedbackAr' => 'المعادلة الثانية غير موزونة، يرجى إعادة التسليم.',
        ])->assertOk();

        // 4. Student sees status=rejected AND teacher's feedbackAr
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/homeworks')
            ->assertOk()
            ->assertJsonPath('data.0.submissionStatus', 'rejected')
            ->assertJsonPath('data.0.feedbackAr', 'المعادلة الثانية غير موزونة، يرجى إعادة التسليم.')
            ->assertJsonPath('data.0.score', null);

        // 5. Student resubmits -> resets status to pending and clears stale rejection feedback
        $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'المعادلة موزونة بالكامل الآن: CH3COONa + NaOH -> CH4 + Na2CO3',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.feedbackAr', null)
            ->assertJsonPath('data.score', null);

        // 6. Teacher grades submission -> student sees graded score & feedback, and cannot overwrite (422)
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);
        $this->patchJson("/api/v1/teacher/homeworks/{$hw->uuid}/submissions/{$submissionUuid}/grade", [
            'status' => 'graded',
            'score' => 19,
            'feedbackAr' => 'إجابة ممتازة وموزونة.',
        ])->assertOk();

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $this->getJson('/api/v1/student/homeworks')
            ->assertOk()
            ->assertJsonPath('data.0.submissionStatus', 'graded')
            ->assertJsonPath('data.0.score', 19)
            ->assertJsonPath('data.0.feedbackAr', 'إجابة ممتازة وموزونة.');

        $this->postJson("/api/v1/student/homeworks/{$hw->uuid}/submit", [
            'solution_text' => 'محاولة تعديل بعد التصحيح',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['homework']);
    }
}
