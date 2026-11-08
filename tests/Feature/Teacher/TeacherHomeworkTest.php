<?php

namespace Tests\Feature\Teacher;

use App\Enums\HomeworkSubmissionStatus;
use App\Enums\HomeworkType;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherHomeworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function createTeacher(string $email = 'teacher.hw@motafawweq.com'): User
    {
        return User::factory()->create([
            'email'             => $email,
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $email = 'student.hw@motafawweq.com'): User
    {
        return User::factory()->create([
            'name'              => 'عمر طارق القاضي',
            'email'             => $email,
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    public function test_teacher_can_create_homework_with_text_content(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'titleAr'          => 'واجب الأسبوع 3: مقارنة التفاعلات الضوئية واللاضوئية',
            'targetLocationId' => 'all',
            'targetLocationAr' => 'جميع الأماكن (كل السناتر + الأونلاين)',
            'type'             => 'text',
            'textContent'      => 'اشرح بالتفصيل دورة كالفن وحسابات جزيئات PGAL.',
            'dueDate'          => '2026-10-15',
            'dueTime'          => '22:00',
            'maxGrade'         => 20,
        ];

        $response = $this->postJson('/api/v1/teacher/homeworks', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titleAr', 'واجب الأسبوع 3: مقارنة التفاعلات الضوئية واللاضوئية')
            ->assertJsonPath('data.type', HomeworkType::TEXT->value)
            ->assertJsonPath('data.maxGrade', 20);

        $this->assertDatabaseHas('homeworks', [
            'teacher_id' => $teacher->id,
            'title_ar'   => 'واجب الأسبوع 3: مقارنة التفاعلات الضوئية واللاضوئية',
            'max_grade'  => 20,
        ]);
    }

    public function test_teacher_can_create_homework_with_pdf_attachment(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $validPdf = UploadedFile::fake()->createWithContent(
            'biology_hw.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );

        $response = $this->postJson('/api/v1/teacher/homeworks', [
            'titleAr'  => 'واجب الأحياء عبر ملف PDF',
            'maxGrade' => 30,
            'file'     => $validPdf,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fileType', 'pdf')
            ->assertJsonPath('data.attachmentName', 'biology_hw.pdf');

        $this->assertDatabaseHas('homeworks', [
            'teacher_id'      => $teacher->id,
            'title_ar'        => 'واجب الأحياء عبر ملف PDF',
            'attachment_name' => 'biology_hw.pdf',
            'file_type'       => 'pdf',
        ]);
    }

    public function test_spoofed_file_upload_is_blocked_and_logged(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $maliciousFile = UploadedFile::fake()->createWithContent(
            'fake.pdf',
            "<?php echo 'malicious shell'; ?>"
        );

        $response = $this->postJson('/api/v1/teacher/homeworks', [
            'titleAr' => 'واجب ملف مشبوه',
            'file'    => $maliciousFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseHas('security_audit_logs', [
            'event' => 'malicious_file_upload_blocked',
        ]);
    }

    public function test_teacher_can_list_homeworks_and_filter_by_location(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        Homework::create([
            'teacher_id'         => $teacher->id,
            'title_ar'           => 'واجب سنتر الدقي',
            'target_location_id' => 'ctr-dokki',
            'target_location_ar' => 'سنتر الدقي النخبة',
            'max_grade'          => 20,
        ]);

        Homework::create([
            'teacher_id'         => $teacher->id,
            'title_ar'           => 'واجب الأونلاين فقط',
            'target_location_id' => 'ctr-online',
            'target_location_ar' => 'أونلاين',
            'max_grade'          => 20,
        ]);

        $response = $this->getJson('/api/v1/teacher/homeworks?target_location_id=ctr-dokki');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.titleAr', 'واجب سنتر الدقي');
    }

    public function test_teacher_can_update_and_delete_homework(): void
    {
        $teacher = $this->createTeacher();
        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'واجب قديم',
            'max_grade'  => 15,
        ]);

        // Update
        $updateResponse = $this->putJson("/api/v1/teacher/homeworks/{$hw->uuid}", [
            'titleAr'  => 'واجب بعد التعديل',
            'maxGrade' => 25,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.titleAr', 'واجب بعد التعديل')
            ->assertJsonPath('data.maxGrade', 25);

        // Delete
        $deleteResponse = $this->deleteJson("/api/v1/teacher/homeworks/{$hw->uuid}");
        $deleteResponse->assertOk();

        $this->assertSoftDeleted('homeworks', [
            'id' => $hw->id,
        ]);
    }

    public function test_student_can_submit_homework_with_text_solution(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $hw = Homework::create([
            'teacher_id'     => $teacher->id,
            'title_ar'       => 'واجب الجينات والوراثة',
            'max_grade'      => 20,
            'total_assigned' => 50,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $payload = [
            'solutionText' => 'حل المسألة الوراثية: النسبة تكون 3 إلى 1 في الجيل الثاني.',
            'locationAr'   => 'سنتر الدقي النخبة',
        ];

        $response = $this->postJson("/api/v1/homeworks/{$hw->uuid}/submit", $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', HomeworkSubmissionStatus::PENDING->value)
            ->assertJsonPath('data.solutionText', 'حل المسألة الوراثية: النسبة تكون 3 إلى 1 في الجيل الثاني.');

        $this->assertDatabaseHas('homework_submissions', [
            'homework_id' => $hw->id,
            'student_id'  => $student->id,
            'status'      => HomeworkSubmissionStatus::PENDING->value,
        ]);

        $hw->refresh();
        $this->assertEquals(1, $hw->submitted_count);
        $this->assertEquals(1, $hw->pending_grading);
        $this->assertEquals(0, $hw->graded_count);
    }

    public function test_student_can_submit_homework_with_file_attachment(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('student.pdf@motafawweq.com');

        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'واجب الرسم التخطيطي',
            'max_grade'  => 20,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $file = UploadedFile::fake()->createWithContent(
            'solution.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF"
        );

        $response = $this->postJson("/api/v1/homeworks/{$hw->uuid}/submit", [
            'file' => $file,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submissionType', 'pdf')
            ->assertJsonPath('data.attachmentName', 'solution.pdf');

        $this->assertDatabaseHas('homework_submissions', [
            'homework_id'     => $hw->id,
            'student_id'      => $student->id,
            'attachment_name' => 'solution.pdf',
            'submission_type' => 'pdf',
        ]);
    }

    public function test_teacher_can_list_submissions_for_their_homework(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('std.list@motafawweq.com');

        $hw = Homework::create([
            'teacher_id'      => $teacher->id,
            'title_ar'        => 'واجب الرصد',
            'max_grade'       => 20,
            'submitted_count' => 1,
            'pending_grading' => 1,
        ]);

        $sub = HomeworkSubmission::create([
            'homework_id'     => $hw->id,
            'student_id'      => $student->id,
            'status'          => HomeworkSubmissionStatus::PENDING,
            'submission_type' => 'text',
            'solution_text'   => 'حل الواجب',
            'submitted_at'    => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $response = $this->getJson("/api/v1/teacher/homeworks/{$hw->uuid}/submissions");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $sub->uuid)
            ->assertJsonPath('data.0.studentNameAr', $student->name)
            ->assertJsonPath('data.0.solutionText', 'حل الواجب');
    }

    public function test_teacher_can_grade_student_submission_and_update_metrics(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('std.grade@motafawweq.com');

        $hw = Homework::create([
            'teacher_id'      => $teacher->id,
            'title_ar'        => 'واجب التقييم',
            'max_grade'       => 20,
            'submitted_count' => 1,
            'pending_grading' => 1,
        ]);

        $sub = HomeworkSubmission::create([
            'homework_id'     => $hw->id,
            'student_id'      => $student->id,
            'status'          => HomeworkSubmissionStatus::PENDING,
            'submission_type' => 'text',
            'solution_text'   => 'حل تفصيلي',
            'submitted_at'    => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'status'     => 'graded',
            'score'      => 19,
            'feedbackAr' => 'ممتاز جداً وإجابة دقيقة.',
        ];

        $response = $this->patchJson("/api/v1/teacher/homeworks/{$hw->uuid}/submissions/{$sub->uuid}/grade", $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', HomeworkSubmissionStatus::GRADED->value)
            ->assertJsonPath('data.score', 19)
            ->assertJsonPath('data.feedbackAr', 'ممتاز جداً وإجابة دقيقة.');

        $hw->refresh();
        $this->assertEquals(1, $hw->graded_count);
        $this->assertEquals(0, $hw->pending_grading);
        $this->assertEquals(19.0, $hw->avg_grade);
    }

    public function test_teacher_can_reject_submission_with_feedback(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent('std.reject@motafawweq.com');

        $hw = Homework::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'واجب المراجعة',
            'max_grade'  => 20,
        ]);

        $sub = HomeworkSubmission::create([
            'homework_id'     => $hw->id,
            'student_id'      => $student->id,
            'status'          => HomeworkSubmissionStatus::PENDING,
            'submission_type' => 'text',
            'solution_text'   => 'حل ناقص',
            'submitted_at'    => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $payload = [
            'status'     => 'rejected',
            'feedbackAr' => 'الملف غير مكتمل، يرجى إعادة الحل.',
        ];

        $response = $this->patchJson("/api/v1/teacher/homeworks/{$hw->uuid}/submissions/{$sub->uuid}/grade", $payload);

        $response->assertOk()
            ->assertJsonPath('data.status', HomeworkSubmissionStatus::REJECTED->value)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.feedbackAr', 'الملف غير مكتمل، يرجى إعادة الحل.');
    }

    public function test_anti_idor_prevents_teacher_from_viewing_or_grading_another_teachers_homework(): void
    {
        $teacherA = $this->createTeacher('teacher.ha@motafawweq.com');
        $teacherB = $this->createTeacher('teacher.hb@motafawweq.com');
        $student = $this->createStudent('std.idor@motafawweq.com');

        $hwA = Homework::create([
            'teacher_id' => $teacherA->id,
            'title_ar'   => 'واجب المدرس أ',
            'max_grade'  => 20,
        ]);

        $sub = HomeworkSubmission::create([
            'homework_id'     => $hwA->id,
            'student_id'      => $student->id,
            'status'          => HomeworkSubmissionStatus::PENDING,
            'submission_type' => 'text',
            'solution_text'   => 'حل طالب أ',
            'submitted_at'    => now(),
        ]);

        // Teacher B tries to view Teacher A's homework submissions
        Sanctum::actingAs($teacherB, [TokenType::ACCESS->value]);

        $response = $this->getJson("/api/v1/teacher/homeworks/{$hwA->uuid}/submissions");
        $response->assertForbidden();

        // Teacher B tries to grade Teacher A's submission
        $gradeResponse = $this->patchJson("/api/v1/teacher/homeworks/{$hwA->uuid}/submissions/{$sub->uuid}/grade", [
            'status' => 'graded',
            'score'  => 15,
        ]);
        $gradeResponse->assertForbidden();

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
        ]);
    }
}

