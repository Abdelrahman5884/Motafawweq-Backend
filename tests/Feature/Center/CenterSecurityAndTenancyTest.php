<?php

namespace Tests\Feature\Center;

use App\Enums\CenterPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterBranch;
use App\Models\CenterExpense;
use App\Models\CenterProfile;
use App\Models\CenterRoom;
use App\Models\CenterStaff;
use App\Models\CenterSubscription;
use App\Models\CenterTask;
use App\Models\CenterTeacher;
use App\Models\SecurityAuditLog;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterSecurityAndTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function createCenter(CenterPlanTier $tier = CenterPlanTier::PRO): User
    {
        $center = User::factory()->center()->create([
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $profile = CenterProfile::create([
            'user_id' => $center->id,
            'name_ar' => 'سنتر '.$center->name,
        ]);
        $profile->forceFill(['plan_tier' => $tier])->save();

        if ($tier !== CenterPlanTier::FREE) {
            CenterSubscription::create([
                'center_id' => $center->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $tier->labelAr(),
                'plan_name_en' => $tier->labelEn(),
                'billing_period' => 'monthly',
                'amount_egp' => $tier === CenterPlanTier::PRO ? 400 : 200,
                'starts_at' => now(),
                'renews_at' => now()->addDays(30),
                'status' => 'active',
            ]);
        }

        return $center;
    }

    public function test_unauthenticated_and_non_center_roles_are_strictly_blocked(): void
    {
        $this->getJson('/api/v1/center/dashboard')->assertStatus(401);

        foreach ([UserRole::STUDENT, UserRole::TEACHER, UserRole::PARENT] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => UserStatus::ACTIVE]);
            Sanctum::actingAs($user, ['access']);

            $this->getJson('/api/v1/center/dashboard')->assertStatus(403);
            $this->getJson('/api/v1/center/branches')->assertStatus(403);
            $this->getJson('/api/v1/center/billing')->assertStatus(403);
        }

        // Unverified center blocked
        $unverified = User::factory()->center()->unverified()->create();
        Sanctum::actingAs($unverified, ['access']);
        $this->getJson('/api/v1/center/dashboard')->assertStatus(403);

        // Suspended center blocked
        $suspended = User::factory()->center()->suspended()->create();
        Sanctum::actingAs($suspended, ['access']);
        $this->getJson('/api/v1/center/dashboard')->assertStatus(403);

        // Refresh token rejected on access endpoints
        $validCenter = $this->createCenter();
        Sanctum::actingAs($validCenter, ['refresh']);
        $this->getJson('/api/v1/center/dashboard')->assertStatus(401);
    }

    public function test_cross_tenant_idor_attempts_are_blocked_and_logged(): void
    {
        $centerA = $this->createCenter(CenterPlanTier::PRO);
        $centerB = $this->createCenter(CenterPlanTier::PRO);

        $branchA = CenterBranch::create([
            'center_id' => $centerA->id,
            'name_ar' => 'فرع الدقي الخاص بسنتر أ',
        ]);

        $roomA = CenterRoom::create([
            'center_id' => $centerA->id,
            'branch_id' => $branchA->id,
            'name_ar' => 'قاعة أ 1',
            'capacity' => 50,
        ]);

        $teacherA = CenterTeacher::create([
            'center_id' => $centerA->id,
            'branch_id' => $branchA->id,
            'name_ar' => 'د. أحمد أ',
            'subject_ar' => 'فيزياء',
            'email' => 'teacher-a@example.com',
        ]);

        $staffA = CenterStaff::create([
            'center_id' => $centerA->id,
            'branch_id' => $branchA->id,
            'name_ar' => 'موظف أ',
            'role' => 'receptionist',
        ]);

        $groupA = StudyGroup::create([
            'teacher_id' => $centerA->id,
            'center_id' => $centerA->id,
            'center_branch_id' => $branchA->id,
            'center_room_id' => $roomA->id,
            'center_teacher_id' => $teacherA->id,
            'name_ar' => 'مجموعة أ',
            'subject_ar' => 'فيزياء',
            'grade_ar' => '3 ثانوي',
            'center_name' => $branchA->name_ar,
            'hall_name' => $roomA->name_ar,
            'status' => 'active',
        ]);

        $expenseA = CenterExpense::create([
            'center_id' => $centerA->id,
            'branch_id' => $branchA->id,
            'receipt_number' => 'REC-2026-999001',
            'title_ar' => 'إيجار سري',
            'category' => 'rent',
            'amount_egp' => 25000,
            'expense_date' => now()->toDateString(),
        ]);

        $taskA = CenterTask::create([
            'center_id' => $centerA->id,
            'title_ar' => 'مهمة خاصة بسنتر أ',
        ]);

        // Authenticate as Center B and attempt cross-tenant IDOR across every domain
        Sanctum::actingAs($centerB, ['access']);

        $this->getJson("/api/v1/center/branches/{$branchA->uuid}")->assertStatus(403);
        $this->putJson("/api/v1/center/branches/{$branchA->uuid}", ['name_ar' => 'Hacked'])->assertStatus(403);
        $this->deleteJson("/api/v1/center/branches/{$branchA->uuid}")->assertStatus(403);

        $this->getJson("/api/v1/center/rooms/{$roomA->uuid}")->assertStatus(403);
        $this->deleteJson("/api/v1/center/rooms/{$roomA->uuid}")->assertStatus(403);

        $this->getJson("/api/v1/center/teachers/{$teacherA->uuid}")->assertStatus(403);
        $this->postJson("/api/v1/center/teachers/{$teacherA->uuid}/resend-invite")->assertStatus(403);

        $this->deleteJson("/api/v1/center/staff/{$staffA->uuid}")->assertStatus(403);

        $this->getJson("/api/v1/center/groups/{$groupA->uuid}")->assertStatus(403);
        $this->getJson("/api/v1/center/groups/{$groupA->uuid}/roster")->assertStatus(403);
        $this->deleteJson("/api/v1/center/groups/{$groupA->uuid}")->assertStatus(403);

        $this->deleteJson("/api/v1/center/financials/expenses/{$expenseA->uuid}")->assertStatus(403);
        $this->postJson("/api/v1/center/financials/settlements/{$teacherA->uuid}/approve")->assertStatus(403);

        $this->patchJson("/api/v1/center/tasks/{$taskA->uuid}/toggle")->assertStatus(403);
        $this->deleteJson("/api/v1/center/tasks/{$taskA->uuid}")->assertStatus(403);

        // Cross-tenant foreign key injection: Center B tries to attach a new room to Center A's branch
        $this->postJson('/api/v1/center/rooms', [
            'name_ar' => 'قاعة متطفلة',
            'branch_id' => $branchA->uuid,
            'capacity' => 30,
        ])->assertStatus(403);

        // Verify immutable IDOR security audit logs were recorded
        $this->assertTrue(
            SecurityAuditLog::where('event', 'center.idor_attempt_blocked')
                ->where('user_id', $centerB->id)
                ->exists()
        );

        // Verify Center B's lists remain 100% isolated (never leak Center A's records)
        $this->getJson('/api/v1/center/branches')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/center/rooms')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/center/teachers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/center/groups')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_mass_assignment_cannot_escalate_center_plan_tier_and_csv_neutralizes_formulas(): void
    {
        $center = $this->createCenter(CenterPlanTier::FREE);
        Sanctum::actingAs($center, ['access']);

        // Attempt to sneak plan_tier & is_verified_center via settings update
        $this->putJson('/api/v1/center/settings', [
            'name_ar' => '<b>أكاديمية الرواد</b> <script></script>',
            'plan_tier' => 'pro',
            'late_margin_minutes' => 20,
        ])->assertOk();

        $profile = CenterProfile::where('user_id', $center->id)->first();
        $this->assertEquals(CenterPlanTier::FREE, $profile->plan_tier);
        // XSS tags stripped
        $this->assertStringNotContainsString('<script>', $profile->name_ar);
        $this->assertStringNotContainsString('<b>', $profile->name_ar);
        $this->assertEquals('أكاديمية الرواد', $profile->name_ar);

        // Create branch with CSV formula payload and verify export neutralizes it
        $this->postJson('/api/v1/center/branches', [
            'name_ar' => '=CMD|calc!A0',
            'address_ar' => '+SUM(A1:A2)',
        ])->assertStatus(201);

        $csvRes = $this->get('/api/v1/center/branches/export');
        $csvRes->assertOk();
        $body = $csvRes->getContent();
        $this->assertStringContainsString("\"'=CMD|calc!A0\"", $body);
        $this->assertStringContainsString("\"'+SUM(A1:A2)\"", $body);
    }

    public function test_audit_logs_endpoint_is_strictly_tenant_isolated(): void
    {
        $centerA = $this->createCenter(CenterPlanTier::PRO);
        $centerB = $this->createCenter(CenterPlanTier::PRO);

        Sanctum::actingAs($centerA, ['access']);
        $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع أ'])->assertStatus(201);

        Sanctum::actingAs($centerB, ['access']);
        $res = $this->getJson('/api/v1/center/audit-logs')->assertOk();

        $events = collect($res->json('data'))->pluck('event')->all();
        $this->assertNotContains('center.branch.created', $events);
    }
}
