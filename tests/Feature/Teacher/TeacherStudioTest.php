<?php

namespace Tests\Feature\Teacher;

use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherStudioTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedTeacher(string $email = 'studio.teacher@motafawweq.com'): array
    {
        $teacher = User::factory()->create([
            'name'              => 'د. سلمى السيد',
            'email'             => $email,
            'phone'             => '010' . random_int(10000000, 99999999),
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $token = $teacher->createToken('test-device', [TokenType::ACCESS->value])->plainTextToken;

        return [$teacher, $token];
    }

    public function test_teacher_can_create_studio_lesson_with_youtube_embed_and_ai_flags(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $course = Course::create([
            'teacher_id'    => $teacher->id,
            'title_ar'      => 'ماستر كلاس الأحياء',
            'stage_id'      => 'sec',
            'stage_name_ar' => 'المرحلة الثانوية',
            'grade_ar'      => 'الصف الثالث الثانوي',
            'subject_ar'    => 'الأحياء',
            'price_egp'     => 350,
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/teacher/lessons', [
                'courseId'    => $course->uuid,
                'title'       => 'البناء الضوئي وحركية الطاقة في النبات',
                'description' => 'شرح مفصل لتركيب البلاستيدة الخضراء والتفاعلات الضوئية واللاضوئية.',
                'subject'     => 'الأحياء',
                'grade'       => '3rd Secondary',
                'classGroup'  => 'cls-dokki-301',
                'mode'        => 'video',
                'videoUrl'    => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'aiFeatures'  => [
                    'speechToText'    => true,
                    'conceptGraph'    => true,
                    'chapterIndexing' => false,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titleAr', 'البناء الضوئي وحركية الطاقة في النبات')
            ->assertJsonPath('data.gradeAr', 'الصف الثالث الثانوي')
            ->assertJsonPath('data.embedUrl', 'https://www.youtube.com/embed/dQw4w9WgXcQ')
            ->assertJsonPath('data.isYoutube', true)
            ->assertJsonPath('data.aiFeatures.speechToText', true)
            ->assertJsonPath('data.aiFeatures.chapterIndexing', false);
    }

    public function test_teacher_can_upload_pdfs_and_ordered_whiteboard_images_and_stream_via_signed_url(): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'حصة التفاعلات الضوئية',
            'subject_ar' => 'الأحياء',
            'grade_ar'   => 'الصف الثالث الثانوي',
            'media_mode' => 'video',
        ]);

        // 1. Upload valid PDF booklet (%PDF-1.4 header)
        $validPdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $pdfFile = UploadedFile::fake()->createWithContent('Photosynthesis_Notes_2026.pdf', $validPdfBytes);

        $pdfRes = $this->withToken($token)
            ->postJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/pdfs", [
                'file'       => $pdfFile,
                'title'      => 'مذكرة شرح البناء الضوئي',
                'pagesCount' => 24,
            ]);

        $pdfRes->assertCreated()
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.title', 'مذكرة شرح البناء الضوئي')
            ->assertJsonPath('data.pagesCount', 24);

        $signedPdfUrl = $pdfRes->json('data.url');
        $this->assertStringContainsString('signature=', $signedPdfUrl);

        // Stream via valid signed URL succeeds
        $streamRes = $this->withToken($token)->get($signedPdfUrl);
        $streamRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Unsigned request to stream endpoint is blocked with 403
        $pdfUuid = $pdfRes->json('data.id');
        $this->withToken($token)
            ->getJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/{$pdfUuid}/stream")
            ->assertStatus(403);

        // 2. Upload 3 Whiteboard Images and test sequential ordering + reordering
        $validPngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $img1 = $this->withToken($token)
            ->postJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/images", [
                'file'  => UploadedFile::fake()->createWithContent('board1.png', $validPngBytes),
                'title' => 'سبورة 1: تشريح البلاستيدة',
            ])->assertCreated()->json('data');

        $img2 = $this->withToken($token)
            ->postJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/images", [
                'file'  => UploadedFile::fake()->createWithContent('board2.png', $validPngBytes),
                'title' => 'سبورة 2: الفسفرة الضوئية',
            ])->assertCreated()->json('data');

        $img3 = $this->withToken($token)
            ->postJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/images", [
                'file'  => UploadedFile::fake()->createWithContent('board3.png', $validPngBytes),
                'title' => 'سبورة 3: دورة كالفن',
            ])->assertCreated()->json('data');

        $this->assertSame(1, $img1['order']);
        $this->assertSame(2, $img2['order']);
        $this->assertSame(3, $img3['order']);

        // Reorder images: move img3 to #1, img1 to #2, img2 to #3
        $reorderRes = $this->withToken($token)
            ->putJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/reorder", [
                'type'        => 'whiteboard_image',
                'ordered_ids' => [$img3['id'], $img1['id'], $img2['id']],
            ]);

        $reorderRes->assertOk()
            ->assertJsonPath('data.0.id', $img3['id'])
            ->assertJsonPath('data.0.order', 1)
            ->assertJsonPath('data.1.id', $img1['id'])
            ->assertJsonPath('data.1.order', 2)
            ->assertJsonPath('data.2.id', $img2['id'])
            ->assertJsonPath('data.2.order', 3);

        // Delete the #1 image (img3) and verify remaining 2 images re-index to #1 and #2
        $this->withToken($token)
            ->deleteJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/{$img3['id']}")
            ->assertOk();

        $lessonShow = $this->withToken($token)
            ->getJson("/api/v1/teacher/lessons/{$lesson->uuid}")
            ->assertOk();

        $this->assertCount(1, $lessonShow->json('data.lessonPdfs'));
        $this->assertCount(2, $lessonShow->json('data.lessonImages'));
        $this->assertSame(1, $lessonShow->json('data.lessonImages.0.order'));
        $this->assertSame(2, $lessonShow->json('data.lessonImages.1.order'));
    }

    public function test_spoofed_non_pdf_files_are_blocked_by_magic_bytes_inspection_and_logged(): void
    {
        Storage::fake('local');

        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'حصة محمية',
            'subject_ar' => 'الأحياء',
            'grade_ar'   => 'الصف الثالث الثانوي',
        ]);

        // Plain text file disguised with .pdf extension (missing %PDF- magic header)
        $fakePdf = UploadedFile::fake()->createWithContent('spoofed_document.pdf', 'INVALID_HEADER_PLAIN_TEXT_CONTENT');

        $this->withToken($token)
            ->postJson("/api/v1/teacher/lessons/{$lesson->uuid}/attachments/pdfs", [
                'file' => $fakePdf,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.upload.malicious_signature_blocked',
            'level'   => 'warning',
        ]);
    }

    public function test_cross_teacher_idor_on_lessons_is_blocked_and_logged(): void
    {
        [$teacherA] = $this->createAuthenticatedTeacher('teacher.a.studio@motafawweq.com');
        [$teacherB, $tokenB] = $this->createAuthenticatedTeacher('teacher.b.studio@motafawweq.com');

        $lessonA = Lesson::create([
            'teacher_id' => $teacherA->id,
            'title_ar'   => 'حصة خاصة بالمعلم أ',
            'subject_ar' => 'الأحياء',
            'grade_ar'   => 'الصف الثالث الثانوي',
        ]);

        $this->withToken($tokenB)
            ->getJson("/api/v1/teacher/lessons/{$lessonA->uuid}")
            ->assertStatus(403);

        $this->withToken($tokenB)
            ->deleteJson("/api/v1/teacher/lessons/{$lessonA->uuid}")
            ->assertStatus(403);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}

