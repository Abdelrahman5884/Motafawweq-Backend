<?php

namespace Tests\Feature\Admin;

use App\Enums\LoginStatus;
use App\Enums\UserRole;
use App\Models\LoginHistory;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardChampionsAnnouncementsAndSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_admin_dashboard_overview_and_real_time_active_users_traffic(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['role' => UserRole::STUDENT, 'last_login_at' => now()]);
        $teacher = User::factory()->create(['role' => UserRole::TEACHER, 'last_login_at' => now()]);

        LoginHistory::create([
            'user_id' => $student->id,
            'email' => $student->email,
            'status' => LoginStatus::SUCCESS,
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subHour(),
        ]);

        LoginHistory::create([
            'user_id' => $teacher->id,
            'email' => $teacher->email,
            'status' => LoginStatus::SUCCESS,
            'ip_address' => '127.0.0.1',
            'created_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($admin, ['access']);

        $res = $this->getJson('/api/v1/admin/dashboard?fresh=1')->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'kpis' => ['mrrEgp', 'totalUsers', 'studentsCount', 'teachersCount', 'activeNowCount', 'avgLessonProcessingSeconds'],
                'demographics' => ['students', 'teachers', 'centers', 'parents'],
                'weeklyActivity' => ['days', 'peakDay', 'averageDailyUsers', 'totalActiveHours'],
                'aiEconomics' => ['totalAudioMinutesProcessed', 'aiProcessingCostEgp', 'aiRevenueEgp', 'grossMarginPct'],
                'championsSpotlight',
                'systemHealth' => ['status', 'maintenanceMode'],
            ],
        ]);
        $this->assertEquals(3, $res->json('data.kpis.totalUsers'));

        // Hourly and Weekly traffic series
        $hourly = $this->getJson('/api/v1/admin/dashboard/active-users?mode=hourly')->assertOk();
        $this->assertCount(8, $hourly->json('data.series'));

        $weekly = $this->getJson('/api/v1/admin/dashboard/active-users?mode=weekly')->assertOk();
        $this->assertCount(7, $weekly->json('data.series'));

        // Invalid mode rejected
        $this->getJson('/api/v1/admin/dashboard/active-users?mode=invalid')->assertStatus(422);
    }

    public function test_champions_and_announcements_crud_sync_with_public_cached_endpoints(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['access']);

        // 1. Champions CRUD
        $c1 = $this->postJson('/api/v1/admin/champions', [
            'nameAr' => 'أحمد إبراهيم الدسوقي',
            'nameEn' => 'Ahmed El-Desouky',
            'scoreAr' => '3,980 نقطة',
            'rankAr' => 'المركز الأول جمهورياً',
            'image' => '/characters/real-male-champ-1.png',
            'isPinned' => true,
        ])->assertStatus(201);

        $c2 = $this->postJson('/api/v1/admin/champions', [
            'nameAr' => 'بطل مخفي',
            'isPinned' => false,
        ])->assertStatus(201);

        $c1Uuid = (string) $c1->json('data.id');
        $c2Uuid = (string) $c2->json('data.id');

        // Public champions returns ONLY pinned
        $pubChamps = $this->getJson('/api/v1/public/champions')->assertOk();
        $this->assertCount(1, $pubChamps->json('data'));
        $this->assertEquals($c1Uuid, $pubChamps->json('data.0.id'));

        // Toggle pin on c2 -> immediately invalidated and visible publicly
        $this->patchJson("/api/v1/admin/champions/{$c2Uuid}/toggle-pin")
            ->assertOk()
            ->assertJsonPath('data.isPinned', true);

        $this->getJson('/api/v1/public/champions')->assertOk()->assertJsonCount(2, 'data');

        // Update & delete
        $this->putJson("/api/v1/admin/champions/{$c1Uuid}", ['scoreAr' => '4,100 نقطة'])
            ->assertOk()
            ->assertJsonPath('data.scoreAr', '4,100 نقطة');
        $this->deleteJson("/api/v1/admin/champions/{$c2Uuid}")->assertOk();

        // 2. Announcements CRUD + Audience Filtering
        $aAll = $this->postJson('/api/v1/admin/announcements', [
            'titleAr' => 'إعلان عام للجميع',
            'contentAr' => 'تفاصيل الإعلان العام لجميع مستخدمي المنصة',
            'audience' => 'all',
            'priority' => 'urgent',
        ])->assertStatus(201);

        $this->postJson('/api/v1/admin/announcements', [
            'titleAr' => 'إعلان خاص بالمعلمين',
            'contentAr' => 'موعد تسوية الأرباح الشهرية للمعلمين',
            'audience' => 'teachers',
            'priority' => 'important',
        ])->assertStatus(201);

        // Public announcements for students only gets 'all' (excludes 'teachers')
        $studentAnn = $this->getJson('/api/v1/public/announcements?audience=students')->assertOk();
        $this->assertCount(1, $studentAnn->json('data'));
        $this->assertEquals($aAll->json('data.id'), $studentAnn->json('data.0.id'));

        // Pause & Delete
        $aUuid = (string) $aAll->json('data.id');
        $this->patchJson("/api/v1/admin/announcements/{$aUuid}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/public/announcements?audience=students')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/admin/announcements/{$aUuid}")->assertOk();
    }

    public function test_system_policies_enforce_maintenance_mode_teacher_registration_and_safe_cache_flush(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['role' => UserRole::STUDENT]);

        Sanctum::actingAs($admin, ['access']);

        // 1. Overview telemetry works
        $this->getJson('/api/v1/admin/system/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'telemetry' => ['overallStatus', 'dbHealthy', 'dbLatencyMs', 'cacheHealthy', 'cacheEpoch'],
                    'aiEconomics',
                    'settings' => ['maintenanceMode', 'allowTeacherRegistration', 'autoCertificates', 'antiPassback'],
                ],
            ]);

        // 2. Disable teacher self-registration -> blocks teacher signup, allows student signup
        $this->putJson('/api/v1/admin/system/settings', [
            'allowTeacherRegistration' => false,
        ])->assertOk()->assertJsonPath('data.allowTeacherRegistration', false);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'معلم جديد',
            'email' => 'blocked.teacher@motafawweq.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => 'teacher',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'طالب جديد',
            'email' => 'allowed.student@motafawweq.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'role' => 'student',
        ])->assertStatus(201);

        // 3. Enable Maintenance Mode -> blocks non-admin with 503, keeps Admin & /ping & /public/status alive!
        Sanctum::actingAs($admin, ['access']);
        $this->putJson('/api/v1/admin/system/settings', [
            'maintenanceMode' => true,
        ])->assertOk()->assertJsonPath('data.maintenanceMode', true);

        $this->getJson('/api/ping')->assertOk();
        $this->getJson('/api/v1/public/status')
            ->assertOk()
            ->assertJsonPath('data.maintenanceMode', true);

        // Admin still accesses admin portal during maintenance
        $this->getJson('/api/v1/admin/dashboard')->assertOk();

        // Student receives 503 MAINTENANCE_MODE with Retry-After header
        Sanctum::actingAs($student, ['access']);
        $this->getJson('/api/v1/student/dashboard')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '300')
            ->assertJson(['error_code' => 'MAINTENANCE_MODE']);

        // Disable maintenance mode again
        Sanctum::actingAs($admin, ['access']);
        $this->putJson('/api/v1/admin/system/settings', [
            'maintenanceMode' => false,
        ])->assertOk();

        // 4. Safe cache flush bumps cache epoch & logs security event
        $flushRes = $this->postJson('/api/v1/admin/system/cache/flush')
            ->assertOk()
            ->assertJsonPath('data.flushed', true);
        $this->assertGreaterThan(1, (int) $flushRes->json('data.cacheEpoch'));

        // 5. Global Audit Logs & Security Summary
        $auditRes = $this->getJson('/api/v1/admin/system/audit-logs?event=admin.system')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($auditRes->json('data')));

        $this->getJson('/api/v1/admin/system/security-summary')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'failedLogins24h',
                    'lockedAccountsNow',
                    'suspendedAccountsCount',
                    'idorAttemptsBlocked24h',
                    'criticalEvents24h',
                    'activeSessionsCount',
                ],
            ]);

        // Verify Republic League start/stop also produces audit logs
        $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'دوري نوفمبر للجمهورية',
            'duration' => 'monthly',
        ])->assertStatus(201);

        $this->postJson('/api/v1/admin/leagues/republic/stop')->assertOk();
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.republic_league.started')->exists());
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.republic_league.stopped')->exists());
    }
}
