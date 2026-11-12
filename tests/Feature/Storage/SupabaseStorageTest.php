<?php

namespace Tests\Feature\Storage;

use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\V1\Storage\SupabaseStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupabaseStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake the supabase storage disk for isolated, reliable automated testing
        Storage::fake('supabase');
    }

    private function createUser(UserRole $role, string $email): array
    {
        $user = User::factory()->create([
            'name'              => 'مستخدم اختباري',
            'email'             => $email,
            'phone'             => '010' . random_int(10000000, 99999999),
            'role'              => $role,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('test-device', [TokenType::ACCESS->value])->plainTextToken;

        return [$user, $token];
    }

    public function test_successful_upload_authorization_generates_presigned_url_and_pending_metadata(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher1@motafawweq.com');

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'حصة الهضم والامتصاص',
            'subject_ar' => 'الأحياء',
            'grade_ar'   => 'الصف الثالث الثانوي',
            'media_mode' => 'audio',
        ]);

        $response = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_media',
                'entity_id'   => $lesson->uuid,
                'filename'    => 'lecture_audio.webm',
                'mime_type'   => 'audio/webm',
                'file_size'   => 15000000, // 15 MB
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'file_id',
                    'upload_url',
                    'storage_path',
                    'mime_type',
                    'expires_in_seconds',
                ],
            ]);

        $fileId = $response->json('data.file_id');

        $this->assertDatabaseHas('stored_files', [
            'uuid'              => $fileId,
            'user_id'           => $teacher->id,
            'entity_type'       => 'lesson_media',
            'entity_id'         => $lesson->uuid,
            'upload_status'     => 'pending',
            'mime_type'         => 'audio/webm',
            'file_size_bytes'   => 15000000,
        ]);
    }

    public function test_unauthorized_upload_attempt_blocks_student_from_teacher_entities(): void
    {
        [$student, $token] = $this->createUser(UserRole::STUDENT, 'student@motafawweq.com');

        $response = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_media',
                'filename'    => 'hack.mp3',
                'mime_type'   => 'audio/mpeg',
                'file_size'   => 2000000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['entity_type']);
    }

    public function test_idor_blocked_teacher_cannot_upload_for_another_teachers_lesson(): void
    {
        [$teacherA] = $this->createUser(UserRole::TEACHER, 'teacherA@motafawweq.com');
        [$teacherB, $tokenB] = $this->createUser(UserRole::TEACHER, 'teacherB@motafawweq.com');

        $lesson = Lesson::create([
            'teacher_id' => $teacherA->id,
            'title_ar'   => 'حصة خاصة بالمعلم أ',
            'subject_ar' => 'الأحياء',
        ]);

        $response = $this->withToken($tokenB)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_media',
                'entity_id'   => $lesson->uuid,
                'filename'    => 'intruder.mp3',
                'mime_type'   => 'audio/mpeg',
                'file_size'   => 1000000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['entity_id']);
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_mime@motafawweq.com');

        $response = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_attachment',
                'filename'    => 'exploit.exe',
                'mime_type'   => 'application/x-msdownload',
                'file_size'   => 500000,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mime_type']);
    }

    public function test_files_exceeding_supabase_free_limit_are_rejected(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_size@motafawweq.com');

        // Exceeds 50 MB (52,428,800 bytes) limit
        $response = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_media',
                'filename'    => 'huge_lecture.mp3',
                'mime_type'   => 'audio/mpeg',
                'file_size'   => 55000000, // 55 MB
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file_size']);
    }

    public function test_successful_upload_confirmation_verifies_object_and_updates_database(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_confirm@motafawweq.com');

        $lesson = Lesson::create([
            'teacher_id' => $teacher->id,
            'title_ar'   => 'حصة المناعة',
            'subject_ar' => 'الأحياء',
        ]);

        $presignRes = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'lesson_media',
                'entity_id'   => $lesson->uuid,
                'filename'    => 'immunity_audio.mp3',
                'mime_type'   => 'audio/mpeg',
                'file_size'   => 8000000,
            ]);

        $fileId = $presignRes->json('data.file_id');
        $stored = StoredFile::where('uuid', $fileId)->firstOrFail();

        // Simulate client successfully uploading directly to the storage disk
        Storage::disk('supabase')->put($stored->storage_path, 'fake audio binary data');

        $confirmRes = $this->withToken($token)
            ->postJson('/api/v1/storage/confirm-upload', [
                'file_id' => $fileId,
            ]);

        $confirmRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('stored_files', [
            'uuid'          => $fileId,
            'upload_status' => 'completed',
        ]);

        // Lesson should now reference this media
        $lesson->refresh();
        $this->assertEquals($stored->storage_path, $lesson->media_path);
        $this->assertEquals('supabase', $lesson->media_disk);
    }

    public function test_missing_object_on_storage_fails_confirmation(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_missing@motafawweq.com');

        $presignRes = $this->withToken($token)
            ->postJson('/api/v1/storage/presign-upload', [
                'entity_type' => 'user_avatar',
                'filename'    => 'my_photo.jpg',
                'mime_type'   => 'image/jpeg',
                'file_size'   => 500000,
            ]);

        $fileId = $presignRes->json('data.file_id');

        // Note: We DO NOT put the file in storage

        $confirmRes = $this->withToken($token)
            ->postJson('/api/v1/storage/confirm-upload', [
                'file_id' => $fileId,
            ]);

        $confirmRes->assertStatus(422)
            ->assertJsonValidationErrors(['file_id']);

        $this->assertDatabaseHas('stored_files', [
            'uuid'          => $fileId,
            'upload_status' => 'failed',
        ]);
    }

    public function test_owner_can_get_private_file_download_url(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_dl_owner@motafawweq.com');

        $file = StoredFile::create([
            'uuid'              => '11111111-2222-3333-4444-555555555555',
            'user_id'           => $teacher->id,
            'entity_type'       => 'user_avatar',
            'original_filename' => 'avatar.jpg',
            'storage_disk'      => 'supabase',
            'storage_path'      => 'users/profiles/avatar.jpg',
            'mime_type'         => 'image/jpeg',
            'file_size_bytes'   => 120000,
            'upload_status'     => 'completed',
        ]);

        Storage::disk('supabase')->put('users/profiles/avatar.jpg', 'avatar content');

        $res = $this->withToken($token)
            ->getJson("/api/v1/storage/download-url/{$file->uuid}");

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.file_id', $file->uuid);
    }

    public function test_unauthorized_user_cannot_access_private_file_download_url(): void
    {
        [$teacherA] = $this->createUser(UserRole::TEACHER, 'teacher_dlA@motafawweq.com');
        [$teacherB, $tokenB] = $this->createUser(UserRole::TEACHER, 'teacher_dlB@motafawweq.com');

        $file = StoredFile::create([
            'uuid'              => '22222222-3333-4444-5555-666666666666',
            'user_id'           => $teacherA->id,
            'entity_type'       => 'user_avatar',
            'original_filename' => 'avatar.jpg',
            'storage_disk'      => 'supabase',
            'storage_path'      => 'users/profiles/avatar_b.jpg',
            'mime_type'         => 'image/jpeg',
            'file_size_bytes'   => 120000,
            'upload_status'     => 'completed',
        ]);

        Storage::disk('supabase')->put('users/profiles/avatar_b.jpg', 'avatar content');

        $res = $this->withToken($tokenB)
            ->getJson("/api/v1/storage/download-url/{$file->uuid}");

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['file_id']);
    }

    public function test_unauthorized_file_deletion_is_blocked(): void
    {
        [$teacherA] = $this->createUser(UserRole::TEACHER, 'teacher_delA@motafawweq.com');
        [$teacherB, $tokenB] = $this->createUser(UserRole::TEACHER, 'teacher_delB@motafawweq.com');

        $file = StoredFile::create([
            'uuid'              => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'user_id'           => $teacherA->id,
            'entity_type'       => 'course_resource',
            'original_filename' => 'exam.pdf',
            'storage_disk'      => 'supabase',
            'storage_path'      => 'courses/resources/exam.pdf',
            'mime_type'         => 'application/pdf',
            'file_size_bytes'   => 500000,
            'upload_status'     => 'completed',
        ]);

        $res = $this->withToken($tokenB)
            ->deleteJson("/api/v1/storage/files/{$file->uuid}");

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['file_id']);
    }

    public function test_authorized_file_deletion_removes_from_storage_and_database(): void
    {
        [$teacher, $token] = $this->createUser(UserRole::TEACHER, 'teacher_del_ok@motafawweq.com');

        $path = 'courses/resources/notes.pdf';
        Storage::disk('supabase')->put($path, 'pdf data');

        $file = StoredFile::create([
            'uuid'              => '99999999-8888-7777-6666-555555555555',
            'user_id'           => $teacher->id,
            'entity_type'       => 'course_resource',
            'original_filename' => 'notes.pdf',
            'storage_disk'      => 'supabase',
            'storage_path'      => $path,
            'mime_type'         => 'application/pdf',
            'file_size_bytes'   => 300000,
            'upload_status'     => 'completed',
        ]);

        $this->assertTrue(Storage::disk('supabase')->exists($path));

        $res = $this->withToken($token)
            ->deleteJson("/api/v1/storage/files/{$file->uuid}");

        $res->assertOk();
        $this->assertFalse(Storage::disk('supabase')->exists($path));
        $this->assertSoftDeleted('stored_files', ['uuid' => $file->uuid]);
    }
}
