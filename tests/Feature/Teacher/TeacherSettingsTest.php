<?php

namespace Tests\Feature\Teacher;

use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function createAuthenticatedTeacher(array $overrides = []): array
    {
        $teacher = User::factory()->create(array_merge([
            'name'              => 'د. سلمى السيد',
            'email'             => 'salma.biology@motafawweq.me',
            'phone'             => '+20 101 234 5678',
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ], $overrides));

        $token = $teacher->createToken('test-device', [TokenType::ACCESS->value])->plainTextToken;

        return [$teacher, $token];
    }

    public function test_teacher_can_fetch_settings_and_default_profile_is_initialized(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/teacher/settings');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'د. سلمى السيد')
            ->assertJsonPath('data.email', 'salma.biology@motafawweq.me')
            ->assertJsonCount(4, 'data.stagesCatalog')
            ->assertJsonCount(4, 'data.stagesTaught');

        $this->assertDatabaseHas('teacher_profiles', [
            'user_id' => $teacher->id,
            'name_ar' => 'د. سلمى السيد',
        ]);
    }

    public function test_student_or_pending_teacher_cannot_access_teacher_settings(): void
    {
        $student = User::factory()->create([
            'role'              => UserRole::STUDENT,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        $studentToken = $student->createToken('test', [TokenType::ACCESS->value])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$studentToken}")
            ->getJson('/api/v1/teacher/settings')
            ->assertStatus(403);

        [$pendingTeacher, $pendingToken] = $this->createAuthenticatedTeacher([
            'email'  => 'pending.teacher@motafawweq.me',
            'status' => UserStatus::PENDING,
        ]);

        $this->withHeader('Authorization', "Bearer {$pendingToken}")
            ->getJson('/api/v1/teacher/settings')
            ->assertStatus(403);
    }

    public function test_teacher_can_update_profile_stages_and_preferences_with_audit_log(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $payload = [
            'nameAr'                     => 'د. سلمى السيد أحمد <script>alert(1)</script>',
            'nameEn'                     => 'Dr. Salma El-Sayed Ahmed',
            'titleAr'                    => 'كبير معلمي الأحياء ومؤلفة سلسلة المتفوق',
            'subjectAr'                  => 'الأحياء والجيولوجيا',
            'phone'                      => '+20 109 876 5432',
            'bioAr'                      => 'خبرة أكثر من 18 عاماً في تدريس مادة الأحياء للثانوية العامة.',
            'stagesTaught'               => [
                'الصف الثالث الثانوي',
                'الصف الثاني الثانوي',
            ],
            'twoFactorEnabled'           => false,
            'notifyWhatsappIntervention' => true,
            'notifyNewEnrollment'        => false,
            'notifyExamSubmission'       => true,
            'autoWatermarkEnabled'       => true,
            'preferredTheme'             => 'dark',
            'preferredLang'              => 'en',
            // Attempted privilege escalation fields (must be ignored)
            'plan_tier'                  => 'pro',
            'rating'                     => 5.0,
            'reviews_count'              => 99999,
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/teacher/settings', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nameAr', 'د. سلمى السيد أحمد alert(1)')
            ->assertJsonPath('data.nameEn', 'Dr. Salma El-Sayed Ahmed')
            ->assertJsonPath('data.phone', '+20 109 876 5432')
            ->assertJsonPath('data.stagesTaught', ['الصف الثالث الثانوي', 'الصف الثاني الثانوي'])
            ->assertJsonPath('data.twoFactorEnabled', false)
            ->assertJsonPath('data.notifyNewEnrollment', false)
            ->assertJsonPath('data.preferredTheme', 'dark')
            ->assertJsonPath('data.preferredLang', 'en')
            ->assertJsonPath('data.planTier', TeacherPlanTier::PLUS->value)
            ->assertJsonPath('data.reviewsCount', 0);

        // User table synced
        $this->assertDatabaseHas('users', [
            'id'    => $teacher->id,
            'name'  => 'د. سلمى السيد أحمد alert(1)',
            'phone' => '+20 109 876 5432',
        ]);

        // Security audit log recorded
        $this->assertTrue(
            SecurityAuditLog::where('user_id', $teacher->id)
                ->where('event', 'teacher.settings.updated')
                ->exists()
        );
    }

    public function test_invalid_curriculum_grade_is_rejected(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/teacher/settings/stages', [
                'stagesTaught' => ['الصف الثالث الثانوي', 'مرحلة غير موجودة'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_teacher_can_upload_valid_avatar_and_invalid_files_are_rejected(): void
    {
        Storage::fake('public');
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        // Valid 1x1 PNG binary (works even when PHP GD extension is not enabled)
        $validPngBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $image = UploadedFile::fake()->createWithContent('portrait.png', $validPngBinary);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/teacher/settings/avatar', [
                'avatar' => $image,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $avatarUrl = $response->json('data.avatar');
        $this->assertStringContainsString('avatars/teachers/', $avatarUrl);

        $this->assertTrue(
            SecurityAuditLog::where('user_id', $teacher->id)
                ->where('event', 'teacher.avatar.uploaded')
                ->exists()
        );

        // Reject spoofed file with non-image content
        $fakeScript = UploadedFile::fake()->createWithContent('malicious.jpg', '<?php echo "hacked"; ?>');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/teacher/settings/avatar', [
                'avatar' => $fakeScript,
            ])
            ->assertStatus(422);
    }
}
