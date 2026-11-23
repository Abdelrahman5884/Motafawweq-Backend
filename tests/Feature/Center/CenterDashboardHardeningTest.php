<?php

namespace Tests\Feature\Center;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\CenterPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\SecurityAuditLog;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterDashboardHardeningTest extends TestCase
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
            'name_ar' => 'سنتر النخبة التعليمي',
            'name_en' => 'Al-Nokhba Center',
        ]);
        $profile->forceFill(['plan_tier' => $tier])->save();

        if ($tier !== CenterPlanTier::FREE) {
            CenterSubscription::create([
                'center_id' => $center->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $tier->labelAr(),
                'billing_period' => 'annual',
                'amount_egp' => 3000,
                'starts_at' => now(),
                'renews_at' => now()->addYear(),
                'status' => 'active',
            ]);
        }

        return $center;
    }

    public function test_fresh_empty_center_dashboard_returns_zeroed_metrics_with_no_static_data(): void
    {
        $center = $this->createCenter(CenterPlanTier::FREE);
        Sanctum::actingAs($center, ['access']);

        $res = $this->getJson('/api/v1/center/dashboard?queue_day=saturday')->assertOk();

        $res->assertJsonPath('data.center.nameAr', 'سنتر النخبة التعليمي')
            ->assertJsonPath('data.branches', [])
            ->assertJsonPath('data.selectedBranchId', 'all')
            ->assertJsonPath('data.kpis.activeStudents', 0)
            ->assertJsonPath('data.kpis.totalRegisteredStudents', 0)
            ->assertJsonPath('data.kpis.activeStudentsPct', 0)
            ->assertJsonPath('data.kpis.teachersCount', 0)
            ->assertJsonPath('data.kpis.roomsCount', 0)
            ->assertJsonPath('data.kpis.totalCapacity', 0)
            ->assertJsonPath('data.kpis.overallOccupancyPct', 0)
            ->assertJsonPath('data.weeklyAttendance.peakDay', null)
            ->assertJsonPath('data.weeklyAttendance.dailyAverage', 0)
            ->assertJsonPath('data.weeklyAttendance.commitmentRate', 0)
            ->assertJsonPath('data.hallOccupancy.rooms', [])
            ->assertJsonPath('data.liveQueue.totalCount', 0)
            ->assertJsonPath('data.liveQueue.completedIds', [])
            ->assertJsonCount(7, 'data.weeklyAttendance.days');
    }

    public function test_dashboard_scopes_period_branch_multiroom_occupancy_peak_and_queue_accurately(): void
    {
        $center = $this->createCenter(CenterPlanTier::PRO);
        Sanctum::actingAs($center, ['access']);

        $br1 = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع الدقي'])->assertStatus(201)->json('data.id');
        $br2 = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع المعادي'])->assertStatus(201)->json('data.id');

        $room1 = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 1', 'branch_id' => $br1, 'capacity' => 40])->assertStatus(201)->json('data.id');
        $room2 = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 2', 'branch_id' => $br2, 'capacity' => 60])->assertStatus(201)->json('data.id');

        $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'د. سلمى السيد',
            'subject_ar' => 'الأحياء',
            'email' => 'salma.dash@test.com',
            'branch_id' => $br1,
        ])->assertStatus(201);

        $g1 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة الصباح',
            'subject_ar' => 'الأحياء',
            'branch_id' => $br1,
            'room_id' => $room1,
            'schedule_slots' => [
                ['day' => 'saturday', 'start_time' => '10:00', 'end_time' => '11:30', 'room_id' => $room1],
            ],
        ])->assertStatus(201)->json('data');

        $g2 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة المساء',
            'subject_ar' => 'الفيزياء',
            'branch_id' => $br2,
            'room_id' => $room2,
            'schedule_slots' => [
                ['day' => 'saturday', 'start_time' => '14:00', 'end_time' => '16:00', 'room_id' => $room2],
            ],
        ])->assertStatus(201)->json('data');

        $st1 = User::factory()->create(['role' => UserRole::STUDENT, 'status' => UserStatus::ACTIVE]);
        $st2 = User::factory()->create(['role' => UserRole::STUDENT, 'status' => UserStatus::ACTIVE]);

        $enr1 = $this->postJson("/api/v1/center/groups/{$g1['id']}/students", ['student_id' => $st1->id])->assertStatus(201)->json('data');
        $enr2 = $this->postJson("/api/v1/center/groups/{$g2['id']}/students", ['student_id' => $st2->id])->assertStatus(201)->json('data');

        Sanctum::actingAs($st1, ['access']);
        $this->postJson("/api/v1/student/groups/invitations/{$enr1['enrollmentId']}/accept")->assertOk();

        Sanctum::actingAs($st2, ['access']);
        $this->postJson("/api/v1/student/groups/invitations/{$enr2['enrollmentId']}/accept")->assertOk();

        Sanctum::actingAs($center, ['access']);

        // Log attendance today for st1 in g1, and 5 days ago for st2 in g2
        $group1Model = StudyGroup::where('uuid', $g1['id'])->firstOrFail();
        $group2Model = StudyGroup::where('uuid', $g2['id'])->firstOrFail();
        $enr1Model = GroupEnrollment::where('uuid', $enr1['enrollmentId'])->firstOrFail();
        $enr2Model = GroupEnrollment::where('uuid', $enr2['enrollmentId'])->firstOrFail();

        $sessionToday = GroupAttendanceSession::create([
            'study_group_id' => $group1Model->id,
            'teacher_id' => $center->id,
            'center_id' => $center->id,
            'session_date' => now()->toDateString(),
            'total_enrolled' => 1,
            'present_count' => 1,
            'absent_count' => 0,
            'attendance_rate' => 100.0,
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $sessionToday->id,
            'study_group_id' => $group1Model->id,
            'group_enrollment_id' => $enr1Model->id,
            'student_id' => $st1->id,
            'status' => AttendanceStatus::PRESENT,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => now(),
            'recorded_by' => $center->id,
        ]);

        $pastDate = now()->subDays(4);
        $sessionPast = GroupAttendanceSession::create([
            'study_group_id' => $group2Model->id,
            'teacher_id' => $center->id,
            'center_id' => $center->id,
            'session_date' => $pastDate->toDateString(),
            'total_enrolled' => 1,
            'present_count' => 1,
            'absent_count' => 0,
            'attendance_rate' => 100.0,
        ]);

        GroupAttendanceRecord::create([
            'attendance_session_id' => $sessionPast->id,
            'study_group_id' => $group2Model->id,
            'group_enrollment_id' => $enr2Model->id,
            'student_id' => $st2->id,
            'status' => AttendanceStatus::LATE,
            'recorded_method' => AttendanceRecordMethod::QR_SCAN,
            'scanned_at' => $pastDate,
            'recorded_by' => $center->id,
        ]);

        // period=today should only count st1 (1 active out of 2 registered)
        $dashToday = $this->getJson('/api/v1/center/dashboard?period=today&queue_day=saturday')->assertOk();
        $dashToday->assertJsonPath('data.kpis.activeStudents', 1)
            ->assertJsonPath('data.kpis.totalRegisteredStudents', 2)
            ->assertJsonCount(2, 'data.branches')
            ->assertJsonPath('data.liveQueue.allItems.0.durationMinutes', 90)
            ->assertJsonPath('data.liveQueue.allItems.1.durationMinutes', 120);

        // period=year should include both st1 and st2
        $this->getJson('/api/v1/center/dashboard?period=year&queue_day=saturday')
            ->assertOk()
            ->assertJsonPath('data.kpis.activeStudents', 2);

        // Scoped to br1
        $this->getJson("/api/v1/center/dashboard?branch_id={$br1}&period=year")
            ->assertOk()
            ->assertJsonPath('data.kpis.roomsCount', 1)
            ->assertJsonPath('data.kpis.totalCapacity', 40)
            ->assertJsonPath('data.kpis.activeStudents', 1)
            ->assertJsonPath('data.kpis.teachersCount', 1);

        // Complete first queue item and verify completedIds persists cleanly
        $this->postJson('/api/v1/center/queue/complete', [
            'group_id' => $g1['id'],
            'slot_id' => $g1['scheduleSlots'][0]['id'],
        ])->assertOk();

        $expectedUniqueId = "{$g1['id']}_{$g1['scheduleSlots'][0]['id']}";
        $this->getJson('/api/v1/center/dashboard?queue_day=saturday')
            ->assertOk()
            ->assertJsonPath('data.liveQueue.completedIds.0', $expectedUniqueId)
            ->assertJsonPath('data.liveQueue.currentActiveItem.groupId', $g2['id']);
    }

    public function test_dashboard_rejects_malformed_branch_idor_and_spoofed_slot_id(): void
    {
        $centerA = $this->createCenter(CenterPlanTier::PRO);
        $centerB = $this->createCenter(CenterPlanTier::PRO);

        Sanctum::actingAs($centerA, ['access']);
        $branchA = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع أ'])->assertStatus(201)->json('data.id');
        $roomA = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة أ', 'branch_id' => $branchA, 'capacity' => 30])->assertStatus(201)->json('data.id');
        $groupA = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة أ',
            'subject_ar' => 'رياضيات',
            'branch_id' => $branchA,
            'room_id' => $roomA,
            'schedule_slots' => [['day' => 'saturday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => $roomA]],
        ])->assertStatus(201)->json('data');

        // Foreign / spoofed slot UUID returns 404
        $this->postJson('/api/v1/center/queue/complete', [
            'group_id' => $groupA['id'],
            'slot_id' => '00000000-0000-4000-8000-000000000099',
        ])->assertStatus(404);

        $this->postJson('/api/v1/center/queue/restore', [
            'group_id' => $groupA['id'],
            'slot_id' => '00000000-0000-4000-8000-000000000099',
        ])->assertStatus(404);

        // Center B validation & IDOR checks
        Sanctum::actingAs($centerB, ['access']);
        $this->getJson('/api/v1/center/dashboard?branch_id=not-a-uuid')->assertStatus(422);
        $this->getJson('/api/v1/center/dashboard?period=invalid')->assertStatus(422);
        $this->getJson('/api/v1/center/dashboard?queue_day=funday')->assertStatus(422);
        $this->getJson("/api/v1/center/dashboard?branch_id={$branchA}")->assertStatus(403);

        $this->assertTrue(
            SecurityAuditLog::where('event', 'center.idor_attempt_blocked')
                ->where('user_id', $centerB->id)
                ->exists()
        );
    }

    public function test_center_registration_persists_center_name_separately_from_manager_name(): void
    {
        $registerRes = $this->postJson('/api/v1/auth/register', [
            'name' => 'Doaa Hassan',
            'email' => 'doaa.center.test@example.com',
            'password' => 'Motafawweq@2026',
            'password_confirmation' => 'Motafawweq@2026',
            'role' => 'center',
            'phone' => '01004732999',
            'center_name' => 'أكاديمية النور التعليمية',
            'governorate' => 'الجيزة / الدقي',
        ])->assertStatus(201);

        $token = (string) $registerRes->json('data.tokens.access_token');

        /** @var User $user */
        $user = User::where('email', 'doaa.center.test@example.com')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->assertDatabaseHas('center_profiles', [
            'user_id' => $user->id,
            'name_ar' => 'أكاديمية النور التعليمية',
            'address_ar' => 'الجيزة / الدقي',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Doaa Hassan')
            ->assertJsonPath('data.center_name_ar', 'أكاديمية النور التعليمية');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/center/dashboard?queue_day=saturday')
            ->assertOk()
            ->assertJsonPath('data.center.nameAr', 'أكاديمية النور التعليمية')
            ->assertJsonPath('data.center.managerName', 'Doaa Hassan');
    }
}
