<?php

namespace Tests\Feature\Center;

use App\Enums\CenterPlanTier;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterBranchesRoomsAndScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    private function createProCenter(): User
    {
        $center = User::factory()->center()->create([
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $profile = CenterProfile::create([
            'user_id' => $center->id,
            'name_ar' => 'أكاديمية الرواد التعليمية',
        ]);
        $profile->forceFill(['plan_tier' => CenterPlanTier::PRO])->save();

        CenterSubscription::create([
            'center_id' => $center->id,
            'plan_tier' => CenterPlanTier::PRO,
            'plan_name_ar' => 'باقة السنتر برو',
            'billing_period' => 'annual',
            'amount_egp' => 3000,
            'starts_at' => now(),
            'renews_at' => now()->addYear(),
            'status' => 'active',
        ]);

        return $center;
    }

    public function test_branches_rooms_crud_room_rename_sync_and_executive_report(): void
    {
        $center = $this->createProCenter();
        Sanctum::actingAs($center, ['access']);

        // Create two branches
        $br1 = $this->postJson('/api/v1/center/branches', [
            'name_ar' => 'فرع الدقي (الرئيسي)',
            'address_ar' => 'شارع التحرير، الدقي',
            'phone' => '+20233456789',
            'manager_name_ar' => 'أ. سامح عبدالحميد',
        ])->assertStatus(201)->json('data');

        $this->assertTrue($br1['isMain']);

        $br2 = $this->postJson('/api/v1/center/branches', [
            'name_ar' => 'فرع مدينة نصر',
            'address_ar' => 'عباس العقاد',
        ])->assertStatus(201)->json('data');

        $this->assertFalse($br2['isMain']);

        // Create room in branch 1
        $room1 = $this->postJson('/api/v1/center/rooms', [
            'name_ar' => 'قاعة 1 (الكبرى)',
            'branch_id' => $br1['id'],
            'capacity' => 60,
            'floor' => 'الطابق الثاني',
            'equipment' => ['شاشة 85 بوصة', 'تكييف مركزي'],
        ])->assertStatus(201)->json('data');

        // Create group assigned to room 1
        $group = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'فيزياء 3 ثانوي — النخبة',
            'subject_ar' => 'الفيزياء',
            'branch_id' => $br1['id'],
            'room_id' => $room1['id'],
            'schedule_slots' => [
                ['day' => 'saturday', 'start_time' => '14:00', 'end_time' => '16:00', 'room_id' => $room1['id']],
            ],
        ])->assertStatus(201)->json('data');

        // Rename room -> hall_name must automatically stay synced on group & slots
        $this->putJson("/api/v1/center/rooms/{$room1['id']}", [
            'name_ar' => 'مدرج أينشتاين الكبير',
            'capacity' => 75,
        ])->assertOk()->assertJsonPath('data.capacity', 75);

        $this->getJson("/api/v1/center/groups/{$group['id']}")
            ->assertOk()
            ->assertJsonPath('data.hallName', 'مدرج أينشتاين الكبير');

        // Filter rooms by branch
        $this->getJson("/api/v1/center/rooms?branch_id={$br1['id']}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/center/rooms?branch_id={$br2['id']}")->assertOk()->assertJsonCount(0, 'data');

        // Executive Administrative Report (Zero Financials)
        $this->getJson('/api/v1/center/reports/executive?period=weekly')
            ->assertOk()
            ->assertJsonPath('data.summary.branchesCount', 2)
            ->assertJsonPath('data.summary.roomsCount', 1)
            ->assertJsonPath('data.summary.totalCapacity', 75)
            ->assertJsonPath('data.summary.activeGroupsCount', 1);

        // Update & delete branch 2
        $this->putJson("/api/v1/center/branches/{$br2['id']}", ['name_ar' => 'فرع مدينة نصر الجديد'])->assertOk();
        $this->deleteJson("/api/v1/center/branches/{$br2['id']}")->assertOk();
        $this->getJson('/api/v1/center/branches')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_zero_conflict_schedule_engine_blocks_room_teacher_and_internal_overlaps(): void
    {
        $center = $this->createProCenter();
        Sanctum::actingAs($center, ['access']);

        $branchId = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع الدقي'])->json('data.id');
        $roomA = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة أ', 'branch_id' => $branchId, 'capacity' => 50])->json('data.id');
        $roomB = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة ب', 'branch_id' => $branchId, 'capacity' => 50])->json('data.id');

        $teacher1 = $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'د. سلمى السيد',
            'subject_ar' => 'الأحياء',
            'email' => 'salma@center.test',
            'branch_id' => $branchId,
        ])->json('data.id');

        $teacher2 = $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'م. أحمد جلال',
            'subject_ar' => 'الفيزياء',
            'email' => 'ahmed@center.test',
            'branch_id' => $branchId,
        ])->json('data.id');

        // 1. Invalid time range (end <= start) rejected
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة خطأ',
            'subject_ar' => 'أحياء',
            'room_id' => $roomA,
            'center_teacher_id' => $teacher1,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '18:00', 'end_time' => '16:00', 'room_id' => $roomA],
            ],
        ])->assertStatus(422);

        // 2. Intra-group overlapping slots rejected
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة تداخل داخلي',
            'subject_ar' => 'أحياء',
            'room_id' => $roomA,
            'center_teacher_id' => $teacher1,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomA],
                ['day' => 'sunday', 'start_time' => '17:00', 'end_time' => '19:00', 'room_id' => $roomA],
            ],
        ])->assertStatus(422);

        // 3. Valid group 1: Room A + Teacher 1 on Sunday 16:00-18:00
        $group1 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'أحياء النخبة',
            'subject_ar' => 'الأحياء',
            'branch_id' => $branchId,
            'room_id' => $roomA,
            'center_teacher_id' => $teacher1,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomA],
            ],
        ])->assertStatus(201)->json('data');

        // 4. Hall overlap: Different teacher (Teacher 2) tries to book Room A at Sunday 17:00-19:00 -> BLOCKED (422)
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'فيزياء متعارضة قاعة',
            'subject_ar' => 'الفيزياء',
            'branch_id' => $branchId,
            'room_id' => $roomA,
            'center_teacher_id' => $teacher2,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '17:00', 'end_time' => '19:00', 'room_id' => $roomA],
            ],
        ])->assertStatus(422);

        // 5. Teacher overlap: Same teacher (Teacher 1) tries to book Room B at Sunday 16:30-18:30 -> BLOCKED (422)
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'أحياء متعارضة مدرس',
            'subject_ar' => 'الأحياء',
            'branch_id' => $branchId,
            'room_id' => $roomB,
            'center_teacher_id' => $teacher1,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '16:30', 'end_time' => '18:30', 'room_id' => $roomB],
            ],
        ])->assertStatus(422);

        // 6. Updating Group 1 with its own slot (e.g. extending to 18:30) does NOT self-conflict!
        $this->putJson("/api/v1/center/groups/{$group1['id']}", [
            'name_ar' => 'أحياء النخبة المحدثة',
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomA],
            ],
        ])->assertOk();

        // 7. Non-overlapping booking (Room B + Teacher 2 at Sunday 16:00-18:00) SUCCEEDS!
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'فيزياء متوازية في قاعة أخرى',
            'subject_ar' => 'الفيزياء',
            'branch_id' => $branchId,
            'room_id' => $roomB,
            'center_teacher_id' => $teacher2,
            'schedule_slots' => [
                ['day' => 'sunday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomB],
            ],
        ])->assertStatus(201);

        // 8. Check Master Schedule & Slot Availability APIs
        $master = $this->getJson('/api/v1/center/schedule?day=sunday')->assertOk();
        $master->assertJsonPath('data.summary.totalRooms', 2)
            ->assertJsonPath('data.summary.occupiedRooms', 2)
            ->assertJsonPath('data.summary.totalSessions', 2);

        $avail = $this->getJson("/api/v1/center/schedule/availability?day=sunday&room_id={$roomA}")->assertOk();
        $slot16 = collect($avail->json('data'))->firstWhere('startTime', '16:00');
        $slot14 = collect($avail->json('data'))->firstWhere('startTime', '14:00');
        $this->assertFalse($slot16['isAvailable']);
        $this->assertTrue($slot14['isAvailable']);

        // 9. Conflict-check API reports hall_overlap
        $this->postJson('/api/v1/center/schedule/check-conflicts', [
            'slots' => [
                ['day' => 'sunday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomA],
            ],
        ])->assertOk()
            ->assertJsonPath('data.hasConflict', true)
            ->assertJsonPath('data.conflicts.0.type', 'hall_overlap');
    }
}
