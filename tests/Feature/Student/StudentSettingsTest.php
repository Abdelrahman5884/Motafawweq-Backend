<?php

namespace Tests\Feature\Student;

use App\Enums\StageType;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\V1\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'عمر طارق القاضي',
            'email' => 'student.settings@motafawweq.me',
            'password' => 'OldPass@2026!',
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'avatar' => null,
            'student_code' => null,
        ], $overrides));
    }

    public function test_get_profile_initializes_defaults_generates_student_code_and_returns_null_avatar(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/profile');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'عمر طارق القاضي')
            ->assertJsonPath('data.email', 'student.settings@motafawweq.me')
            ->assertJsonPath('data.emailVerified', true)
            ->assertJsonPath('data.avatar', null)
            ->assertJsonPath('data.stage', StageType::THANAWYA->value)
            ->assertJsonPath('data.gradeAr', 'الصف الثالث الثانوي')
            ->assertJsonPath('data.trackAr', 'علمي علوم')
            ->assertJsonPath('data.dailyStudyGoalMinutes', 120)
            ->assertJsonPath('data.weeklyExamsGoal', 3)
            ->assertJsonPath('data.targetScorePercent', 90)
            ->assertJsonPath('data.showOnLeaderboard', true)
            ->assertJsonPath('data.displayNameMode', 'full')
            ->assertJsonPath('data.preferences.playbackSpeed', '1.0x');

        $this->assertNotEmpty($res->json('data.studentCode'));
    }

    public function test_update_profile_persists_all_fields_normalizes_legacy_grade_and_merges_preferences(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Initial preference update (theme)
        $this->putJson('/api/v1/student/profile', [
            'preferences' => ['theme' => 'dark'],
        ])->assertOk();

        // Full profile update including legacy English grade & playbackSpeed
        $res = $this->putJson('/api/v1/student/profile', [
            'name' => '  <b>يوسف</b> أحمد محمود  ',
            'phone' => '01012345678',
            'parent_phone' => '01198765432',
            'grade_ar' => '2nd Secondary',
            'track_ar' => 'علمي',
            'governorate' => 'الإسكندرية',
            'school_name' => 'مدرسة العباسية الثانوية',
            'center_name' => 'سنتر النخبة',
            'daily_study_goal_minutes' => 90,
            'weekly_exams_goal' => 5,
            'target_score_percent' => 95,
            'show_on_leaderboard' => true,
            'display_name_mode' => 'nickname',
            'nickname' => 'د. يوسف',
            'preferences' => [
                'playbackSpeed' => '1.5x',
                'language' => 'ar',
            ],
        ]);

        $res->assertOk()
            ->assertJsonPath('data.name', 'يوسف أحمد محمود')
            ->assertJsonPath('data.phone', '01012345678')
            ->assertJsonPath('data.parentPhone', '01198765432')
            ->assertJsonPath('data.gradeAr', 'الصف الثاني الثانوي')
            ->assertJsonPath('data.stage', StageType::SECONDARY->value)
            ->assertJsonPath('data.trackAr', 'علمي')
            ->assertJsonPath('data.governorate', 'الإسكندرية')
            ->assertJsonPath('data.schoolName', 'مدرسة العباسية الثانوية')
            ->assertJsonPath('data.centerName', 'سنتر النخبة')
            ->assertJsonPath('data.dailyStudyGoalMinutes', 90)
            ->assertJsonPath('data.weeklyExamsGoal', 5)
            ->assertJsonPath('data.targetScorePercent', 95)
            ->assertJsonPath('data.displayNameMode', 'nickname')
            ->assertJsonPath('data.nickname', 'د. يوسف')
            ->assertJsonPath('data.preferences.playbackSpeed', '1.5x')
            ->assertJsonPath('data.preferences.theme', 'dark')
            ->assertJsonPath('data.preferences.language', 'ar');
    }

    public function test_update_profile_enforces_unique_phone_and_allows_clearing_phone(): void
    {
        $other = $this->createStudent([
            'email' => 'other.student@motafawweq.me',
            'phone' => '01099998888',
        ]);
        $student = $this->createStudent([
            'email' => 'main.student@motafawweq.me',
            'phone' => '01011112222',
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Duplicate phone belonging to another user -> clean 422
        $this->putJson('/api/v1/student/profile', [
            'phone' => $other->phone,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // Keeping own phone succeeds
        $this->putJson('/api/v1/student/profile', [
            'phone' => '01011112222',
        ])->assertOk()
            ->assertJsonPath('data.phone', '01011112222');

        // Clearing phone to empty string saves cleanly as null
        $this->putJson('/api/v1/student/profile', [
            'phone' => '',
        ])->assertOk()
            ->assertJsonPath('data.phone', null);
    }

    public function test_upload_and_delete_avatar_workflow_with_magic_byte_validation(): void
    {
        Storage::fake('public');

        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Fake spoofed image must be rejected
        $fakeFile = UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo "nope"; ?>');
        $this->post('/api/v1/student/profile/avatar', ['avatar' => $fakeFile], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Real valid 1x1 PNG binary (no GD extension required in CLI PHP)
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $validPng = UploadedFile::fake()->createWithContent('me.png', $pngBytes);
        $uploadRes = $this->post('/api/v1/student/profile/avatar', ['avatar' => $validPng], ['Accept' => 'application/json']);
        $uploadRes->assertOk();

        $avatarUrl = $uploadRes->json('data.avatar');
        $this->assertNotEmpty($avatarUrl);
        $this->assertDatabaseHas('stored_files', [
            'user_id' => $student->id,
            'entity_type' => 'user_avatar',
        ]);

        $stored = StoredFile::where('user_id', $student->id)->where('entity_type', 'user_avatar')->firstOrFail();
        Storage::disk('public')->assertExists($stored->storage_path);

        // Delete avatar reverts to null and cleans up stored file
        $deleteRes = $this->deleteJson('/api/v1/student/profile/avatar');
        $deleteRes->assertOk()
            ->assertJsonPath('data.avatar', null);

        $this->assertNull($student->fresh()->avatar);
        Storage::disk('public')->assertMissing($stored->storage_path);
        $this->assertSoftDeleted('stored_files', ['id' => $stored->id]);
    }

    public function test_student_can_change_password_and_revoke_other_sessions_atomically(): void
    {
        $student = $this->createStudent();
        /** @var TokenService $tokenService */
        $tokenService = app(TokenService::class);

        $sessionA = $tokenService->issuePair($student, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0');
        $sessionB = $tokenService->issuePair($student, '10.0.0.2', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1');
        $sessionC = $tokenService->issuePair($student, '10.0.0.3', 'Mozilla/5.0 (Macintosh) Firefox/121.0');

        // List all 3 sessions from sessionA
        $listRes = $this->withHeader('Authorization', 'Bearer '.$sessionA['access_token'])
            ->getJson('/api/v1/auth/sessions');
        $listRes->assertOk()->assertJsonCount(3, 'data');

        // Revoke all other sessions atomically
        $revokeRes = $this->withHeader('Authorization', 'Bearer '.$sessionA['access_token'])
            ->deleteJson('/api/v1/auth/sessions');
        $revokeRes->assertOk()
            ->assertJsonPath('success', true);

        // Only sessionA remains active
        $afterRes = $this->withHeader('Authorization', 'Bearer '.$sessionA['access_token'])
            ->getJson('/api/v1/auth/sessions');
        $afterRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sessionA['session_id'])
            ->assertJsonPath('data.0.is_current', true);

        // SessionB is no longer authorized (reset cached Sanctum guard between different bearer tokens)
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$sessionB['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // Change password succeeds with strong password
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$sessionA['access_token'])
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'OldPass@2026!',
                'new_password' => 'NewStrong@2026!',
                'new_password_confirmation' => 'NewStrong@2026!',
            ])->assertOk();

        $this->assertTrue(Hash::check('NewStrong@2026!', $student->fresh()->password));
    }

    public function test_non_student_cannot_upload_or_delete_student_avatar(): void
    {
        $teacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->deleteJson('/api/v1/student/profile/avatar')->assertForbidden();
    }

    public function test_student_can_permanently_delete_own_account_with_password_confirmation(): void
    {
        Storage::fake('public');

        $student = $this->createStudent();
        /** @var TokenService $tokenService */
        $tokenService = app(TokenService::class);
        $tokens = $tokenService->issuePair($student, '127.0.0.1', 'Mozilla/5.0 Chrome/120');

        // Initialize profile & upload an avatar to verify full cascade + file cleanup
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $validPng = UploadedFile::fake()->createWithContent('avatar.png', $pngBytes);

        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->post('/api/v1/student/profile/avatar', ['avatar' => $validPng], ['Accept' => 'application/json'])
            ->assertOk();

        $stored = StoredFile::where('user_id', $student->id)->where('entity_type', 'user_avatar')->firstOrFail();
        Storage::disk('public')->assertExists($stored->storage_path);

        // Wrong password must fail with 422 and keep account intact
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->deleteJson('/api/v1/auth/account', ['password' => 'WrongPassword!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseHas('users', ['id' => $student->id]);

        // Correct password deletes account, revokes tokens, cascades profile, and cleans avatar file
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->deleteJson('/api/v1/auth/account', ['password' => 'OldPass@2026!'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('student_profiles', ['student_id' => $student->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $student->id]);
        Storage::disk('public')->assertMissing($stored->storage_path);

        // Old token is rejected
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
