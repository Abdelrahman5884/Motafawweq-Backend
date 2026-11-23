<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSecurityAndAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_unauthenticated_and_non_admin_roles_are_strictly_blocked_from_all_admin_endpoints(): void
    {
        $endpoints = [
            ['GET', '/api/v1/admin/dashboard'],
            ['GET', '/api/v1/admin/dashboard/active-users'],
            ['GET', '/api/v1/admin/users'],
            ['GET', '/api/v1/admin/users/verifications'],
            ['GET', '/api/v1/admin/courses'],
            ['GET', '/api/v1/admin/champions'],
            ['GET', '/api/v1/admin/financials/overview'],
            ['GET', '/api/v1/admin/financials/plans'],
            ['GET', '/api/v1/admin/financials/payouts'],
            ['GET', '/api/v1/admin/financials/coupons'],
            ['GET', '/api/v1/admin/attendance/gates'],
            ['GET', '/api/v1/admin/announcements'],
            ['GET', '/api/v1/admin/system/overview'],
            ['GET', '/api/v1/admin/system/audit-logs'],
            ['GET', '/api/v1/admin/system/security-summary'],
        ];

        // 1. Unauthenticated -> 401
        foreach ($endpoints as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }

        // 2. Non-admin active verified roles -> 403
        foreach ([UserRole::STUDENT, UserRole::TEACHER, UserRole::PARENT, UserRole::CENTER] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'status' => UserStatus::ACTIVE,
                'email_verified_at' => now(),
            ]);
            Sanctum::actingAs($user, ['access']);

            foreach ($endpoints as [$method, $uri]) {
                $this->json($method, $uri)->assertStatus(403);
            }
        }

        // 3. Unverified admin -> 403
        $unverifiedAdmin = User::factory()->admin()->unverified()->create();
        Sanctum::actingAs($unverifiedAdmin, ['access']);
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);

        // 4. Suspended admin using real Bearer token -> 403 + tokens wiped
        app('auth')->forgetGuards();
        $suspendedAdmin = User::factory()->admin()->suspended()->create();
        $token = $suspendedAdmin->createToken('adm', ['access'])->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // 5. Refresh token rejected on admin endpoints -> 401 INVALID_TOKEN_TYPE
        $validAdmin = User::factory()->admin()->create();
        Sanctum::actingAs($validAdmin, ['refresh']);
        $this->getJson('/api/v1/admin/dashboard')
            ->assertStatus(401)
            ->assertJson(['error_code' => 'INVALID_TOKEN_TYPE']);
    }

    public function test_admin_cannot_provision_or_escalate_accounts_to_admin_role(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['role' => UserRole::STUDENT]);

        Sanctum::actingAs($admin, ['access']);

        // Cannot create a new admin via API
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Rogue Admin',
            'email' => 'rogue@motafawweq.test',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'rogue@motafawweq.test']);

        // Cannot promote existing student to admin
        $this->putJson("/api/v1/admin/users/{$student->id}", [
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertEquals(UserRole::STUDENT, $student->fresh()->role);
    }

    public function test_admin_cannot_modify_suspend_reset_or_delete_self_or_peer_admin(): void
    {
        $adminA = User::factory()->admin()->create();
        $adminB = User::factory()->admin()->create();

        Sanctum::actingAs($adminA, ['access']);

        foreach ([$adminA, $adminB] as $protectedTarget) {
            $this->putJson("/api/v1/admin/users/{$protectedTarget->id}", ['name' => 'Hacked Name'])
                ->assertStatus(403);
            $this->patchJson("/api/v1/admin/users/{$protectedTarget->id}/toggle-status")
                ->assertStatus(403);
            $this->postJson("/api/v1/admin/users/{$protectedTarget->id}/reset-password")
                ->assertStatus(403);
            $this->postJson("/api/v1/admin/users/{$protectedTarget->id}/revoke-sessions")
                ->assertStatus(403);
            $this->deleteJson("/api/v1/admin/users/{$protectedTarget->id}")
                ->assertStatus(403);
        }

        // Verify accounts remained completely untouched and immutable audit logs recorded
        $this->assertDatabaseHas('users', ['id' => $adminA->id, 'status' => UserStatus::ACTIVE->value]);
        $this->assertDatabaseHas('users', ['id' => $adminB->id, 'status' => UserStatus::ACTIVE->value]);
        $this->assertTrue(
            SecurityAuditLog::where('event', 'admin.self_or_peer_admin_mutation_blocked')
                ->where('user_id', $adminA->id)
                ->exists()
        );
    }

    public function test_champion_image_url_blocks_xss_ssrf_and_path_traversal(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['access']);

        $maliciousUrls = [
            'javascript:alert(1)',
            'data:image/svg+xml,<svg onload=alert(1)>',
            'http://example.com/insecure.png',
            'https://127.0.0.1/internal.png',
            'https://169.254.169.254/latest/meta-data/',
            'https://localhost/admin.png',
            '/characters/../../etc/passwd',
            '/etc/passwd',
        ];

        foreach ($maliciousUrls as $badUrl) {
            $this->postJson('/api/v1/admin/champions', [
                'nameAr' => 'بطل اختبار',
                'image' => $badUrl,
            ])->assertStatus(422);
        }

        // Safe relative path and public HTTPS URL succeed
        $this->postJson('/api/v1/admin/champions', [
            'nameAr' => 'بطل آمن محلي',
            'image' => '/characters/real-male-champ-1.png',
        ])->assertStatus(201);

        $this->postJson('/api/v1/admin/champions', [
            'nameAr' => 'بطل آمن سحابي',
            'image' => 'https://cdn.motafawweq.me/avatars/champ-1.png',
        ])->assertStatus(201);
    }

    public function test_system_settings_rejects_unknown_injected_keys_and_strips_xss(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['access']);

        $this->putJson('/api/v1/admin/system/settings', [
            'maintenanceMode' => false,
            'arbitrary_backdoor_key' => 'evil',
        ])->assertStatus(422);

        // XSS sanitization on announcements
        $res = $this->postJson('/api/v1/admin/announcements', [
            'titleAr' => '<b>إعلان</b> <script>alert(1)</script>هام',
            'contentAr' => '<img src=x onerror=alert(1)>محتوى الإعلان الآمن',
            'audience' => 'all',
            'priority' => 'urgent',
        ])->assertStatus(201);

        $this->assertStringNotContainsString('<script>', (string) $res->json('data.titleAr'));
        $this->assertStringNotContainsString('<b>', (string) $res->json('data.titleAr'));
        $this->assertStringNotContainsString('<img', (string) $res->json('data.contentAr'));
    }
}
