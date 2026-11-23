<?php

namespace Tests\Feature\Center;

use App\Enums\CenterPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterGroupsRosterAndSmartAttendanceTest extends TestCase
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
            'name_ar' => 'سنتر المتفوق',
            'late_margin_minutes' => 15,
        ]);
        $profile->forceFill(['plan_tier' => $tier])->save();

        if ($tier !== CenterPlanTier::FREE) {
            CenterSubscription::create([
                'center_id' => $center->id,
                'plan_tier' => $tier,
                'plan_name_ar' => $tier->labelAr(),
                'billing_period' => 'monthly',
                'amount_egp' => $tier === CenterPlanTier::PRO ? 400 : 200,
                'starts_at' => now(),
                'renews_at' => now()->addDays(30),
                'status' => 'active',
            ]);
        }

        return $center;
    }

    public function test_group_enrollment_student_join_code_id_card_and_signed_qr_attendance(): void
    {
        $center = $this->createCenter(CenterPlanTier::PRO);
        $platformStudent = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'name' => 'عمر طارق القاضي',
            'phone' => '01024589912',
            'student_code' => 'STD-778899',
            'avatar' => 'https://example.com/avatars/omar.jpg',
        ]);

        Sanctum::actingAs($center, ['access']);

        // Platform student search works with or without query and returns student avatar
        $searchRes = $this->getJson('/api/v1/center/students/search')->assertOk()->json('data');
        $this->assertNotEmpty($searchRes);
        $this->assertSame('https://example.com/avatars/omar.jpg', $searchRes[0]['avatar']);

        $branchId = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع الدقي'])->json('data.id');
        $roomId = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 1', 'branch_id' => $branchId, 'capacity' => 50])->json('data.id');

        $group = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'أحياء 3 ثانوي النخبة',
            'subject_ar' => 'الأحياء',
            'branch_id' => $branchId,
            'room_id' => $roomId,
            'price_egp' => 500,
            'max_students' => 2,
            'schedule_slots' => [
                ['day' => 'tuesday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomId],
            ],
        ])->assertStatus(201)->json('data');

        // 1. Adding an existing platform student sends an invitation (pending + teacher_invite) for the student to accept
        $enr1 = $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'student_id' => (string) $platformStudent->id,
            'payment_status' => 'due',
            'due_amount_egp' => 200,
        ])->assertStatus(201)->json('data');

        $this->assertTrue($enr1['isInvitation']);
        $this->assertSame('pending', $enr1['enrollmentStatus']);
        $this->assertSame('teacher_invite', $enr1['joinMethod']);
        $this->assertSame('https://example.com/avatars/omar.jpg', $enr1['avatar']);

        // Duplicate invitation while pending is rejected
        $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'student_id' => (string) $platformStudent->id,
        ])->assertStatus(422);

        // Center cannot self-accept its own sent invitation; the invited student must accept from their account
        $this->postJson("/api/v1/center/groups/{$group['id']}/enrollments/{$enr1['enrollmentId']}/accept")
            ->assertStatus(422);

        // Student accepts the center invitation from their account -> becomes enrolled with barcode & passcode
        Sanctum::actingAs($platformStudent, ['access']);
        $acceptedInv = $this->postJson("/api/v1/student/groups/invitations/{$enr1['enrollmentId']}/accept")
            ->assertOk()
            ->json('data');
        $this->assertSame('enrolled', $acceptedInv['enrollmentStatus']);
        $this->assertNotEmpty($acceptedInv['studentBarcode']);
        $this->assertNotEmpty($acceptedInv['studentPasscode']);
        $enr1['barcode'] = $acceptedInv['studentBarcode'];
        $enr1['passcode'] = $acceptedInv['studentPasscode'];

        Sanctum::actingAs($center, ['access']);

        // Duplicate enrollment after acceptance is rejected
        $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'student_id' => (string) $platformStudent->id,
        ])->assertStatus(422);

        // 2. Manual walk-in non-platform student registration enrolls directly & generates barcode immediately
        $enr2 = $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'name_ar' => 'سارة خالد المنشاوي',
            'phone' => '01199887766',
            'grade_ar' => 'الصف الثالث الثانوي',
            'parent_name_ar' => 'خالد المنشاوي',
            'parent_phone' => '01001122334',
        ])->assertStatus(201)->json('data');
        $this->assertFalse($enr2['isInvitation']);
        $this->assertSame('enrolled', $enr2['enrollmentStatus']);
        $this->assertNotEmpty($enr2['barcode']);

        // 3. Capacity limit (max_students = 2) enforced on 3rd student
        $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'name_ar' => 'طالب زائد',
            'phone' => '01200001111',
        ])->assertStatus(422);

        // 4. Generate printable student ID card with signed HMAC QR and student avatar
        $card = $this->getJson("/api/v1/center/groups/{$group['id']}/enrollments/{$enr1['enrollmentId']}/card")
            ->assertOk()
            ->json('data');

        $this->assertStringStartsWith('MOTAFAWWEQ:ATTEND:', $card['signedQrPayload']);
        $this->assertSame('https://example.com/avatars/omar.jpg', $card['avatar']);

        // 5. Tampered HMAC QR payload MUST be rejected!
        $tamperedQr = substr($card['signedQrPayload'], 0, -4).'0000';
        $this->postJson("/api/v1/center/groups/{$group['id']}/attendance/scan", [
            'code' => $tamperedQr,
        ])->assertStatus(422);

        // 6. Valid signed HMAC QR succeeds
        $this->postJson("/api/v1/center/groups/{$group['id']}/attendance/scan", [
            'code' => $card['signedQrPayload'],
        ])->assertOk()
            ->assertJsonPath('data.alreadyRecorded', false)
            ->assertJsonPath('data.status', 'present');

        // 7. Re-scanning same student returns alreadyRecorded = true gracefully (idempotent)
        $this->postJson("/api/v1/center/groups/{$group['id']}/attendance/scan", [
            'code' => $enr1['barcode'],
        ])->assertOk()
            ->assertJsonPath('data.alreadyRecorded', true);

        // 8. Smart Gate Auto-Matcher detects late arrival (Tuesday 16:25 > 16:00 + 15min grace -> 'late')
        $smartRes = $this->postJson('/api/v1/center/attendance/smart-scan', [
            'code' => $enr2['qrPayload'],
            'simulated_day' => 'tuesday',
            'simulated_time' => '16:25',
        ])->assertOk();

        $smartRes->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.group.id', $group['id'])
            ->assertJsonPath('data.matchedSlot.day', 'tuesday');

        // 9. Roster reflects live present counts + Sessions history & details work
        $this->getJson("/api/v1/center/groups/{$group['id']}/roster")
            ->assertOk()
            ->assertJsonPath('data.stats.enrolledCount', 2)
            ->assertJsonPath('data.stats.presentCount', 2);

        $sessions = $this->getJson("/api/v1/center/groups/{$group['id']}/attendance/sessions")->assertOk()->json('data');
        $this->assertCount(1, $sessions);

        $this->getJson("/api/v1/center/groups/{$group['id']}/attendance/sessions/{$sessions[0]['id']}")
            ->assertOk()
            ->assertJsonCount(2, 'data.roster');

        // 10. Parent Absence Alert (Pro feature) + Live Register + CSV Export
        $this->postJson('/api/v1/center/attendance/absence-alerts', [
            'group_id' => $group['id'],
            'enrollment_id' => $enr2['enrollmentId'],
            'channel' => 'whatsapp',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'sent');

        $this->getJson('/api/v1/center/attendance/records')
            ->assertOk()
            ->assertJsonPath('data.summary.scannedPresentCount', 2);

        $this->get('/api/v1/center/attendance/export')->assertOk();

        // 11. Cross-portal check: Student can join another open Center group via join_code & Center accepts them
        $groupOpen = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'كيمياء مفتوحة',
            'subject_ar' => 'الكيمياء',
            'branch_id' => $branchId,
            'room_id' => $roomId,
            'schedule_slots' => [
                ['day' => 'thursday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomId],
            ],
        ])->assertStatus(201)->json('data');

        $studentNew = User::factory()->create(['role' => UserRole::STUDENT, 'status' => UserStatus::ACTIVE]);
        Sanctum::actingAs($studentNew, ['access']);
        $joinRes = $this->postJson('/api/v1/student/groups/join', [
            'join_code' => $groupOpen['joinCode'],
        ])->assertStatus(201);
        $pendingEnrollmentUuid = $joinRes->json('data.id');
        $this->assertNotEmpty($pendingEnrollmentUuid);

        Sanctum::actingAs($center, ['access']);
        $this->postJson("/api/v1/center/groups/{$groupOpen['id']}/enrollments/{$pendingEnrollmentUuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.enrollmentId', $pendingEnrollmentUuid);

        // 12. Teacher name override, code regeneration, payment status update preserving attendance, and multi-segment QR scan
        $customTeacherGroup = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'فيزياء متقدمة',
            'subject_ar' => 'الفيزياء',
            'teacher_name_override' => 'د. طارق منصور',
            'room_id' => $roomId,
            'schedule_slots' => [
                ['day' => 'monday', 'start_time' => '16:00', 'end_time' => '18:00', 'room_id' => $roomId],
            ],
        ])->assertStatus(201)->json('data');

        $this->assertSame('د. طارق منصور', $customTeacherGroup['teacherNameAr']);

        $regen = $this->postJson("/api/v1/center/groups/{$customTeacherGroup['id']}/regenerate-code")
            ->assertOk()
            ->json('data');
        $this->assertNotSame($customTeacherGroup['joinCode'], $regen['joinCode']);

        // Update payment on enr1 (who was scanned present in step 6) and ensure todayStatus stays 'present'
        $updatedPayment = $this->patchJson("/api/v1/center/groups/{$group['id']}/enrollments/{$enr1['enrollmentId']}/payment", [
            'payment_status' => 'paid',
            'due_amount_egp' => 0,
        ])->assertOk()->json('data');

        $this->assertSame('paid', $updatedPayment['paymentStatus']);
        $this->assertSame('present', $updatedPayment['todayStatus']);

        // Multi-segment MOTAFAWWEQ:STUDENT:{id}:{passcode}:{barcode} resolves in a single scan call
        $multiSegmentQr = "MOTAFAWWEQ:STUDENT:{$enr2['enrollmentId']}:{$enr2['passcode']}:{$enr2['barcode']}";
        $this->postJson("/api/v1/center/groups/{$group['id']}/attendance/scan", [
            'code' => $multiSegmentQr,
        ])->assertOk()
            ->assertJsonPath('data.enrollmentId', $enr2['enrollmentId']);
    }

    public function test_dashboard_live_daily_queue_complete_restore_and_reset(): void
    {
        $center = $this->createCenter(CenterPlanTier::PRO);
        Sanctum::actingAs($center, ['access']);

        $roomId = $this->postJson('/api/v1/center/rooms', ['name_ar' => 'قاعة 1', 'capacity' => 40])->json('data.id');

        $g1 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'حصة الصباح',
            'subject_ar' => 'رياضيات',
            'room_id' => $roomId,
            'schedule_slots' => [['day' => 'saturday', 'start_time' => '10:00', 'end_time' => '12:00', 'room_id' => $roomId]],
        ])->assertStatus(201)->json('data');

        $g2 = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'حصة المساء',
            'subject_ar' => 'فيزياء',
            'room_id' => $roomId,
            'schedule_slots' => [['day' => 'saturday', 'start_time' => '14:00', 'end_time' => '16:00', 'room_id' => $roomId]],
        ])->assertStatus(201)->json('data');

        // Chronological order: 10:00 first (#1), 14:00 second
        $dash1 = $this->getJson('/api/v1/center/dashboard?queue_day=saturday')->assertOk();
        $dash1->assertJsonPath('data.liveQueue.currentActiveItem.groupId', $g1['id'])
            ->assertJsonPath('data.liveQueue.activeCount', 2)
            ->assertJsonPath('data.liveQueue.completedCount', 0);

        // Complete first session -> second session advances to #1!
        $this->postJson('/api/v1/center/queue/complete', [
            'group_id' => $g1['id'],
            'slot_id' => $g1['scheduleSlots'][0]['id'],
        ])->assertOk();

        $this->getJson('/api/v1/center/dashboard?queue_day=saturday')
            ->assertOk()
            ->assertJsonPath('data.liveQueue.currentActiveItem.groupId', $g2['id'])
            ->assertJsonPath('data.liveQueue.activeCount', 1)
            ->assertJsonPath('data.liveQueue.completedCount', 1);

        // Restore first session -> back at #1
        $this->postJson('/api/v1/center/queue/restore', [
            'group_id' => $g1['id'],
            'slot_id' => $g1['scheduleSlots'][0]['id'],
        ])->assertOk();

        $this->getJson('/api/v1/center/dashboard?queue_day=saturday')
            ->assertOk()
            ->assertJsonPath('data.liveQueue.currentActiveItem.groupId', $g1['id']);

        // Complete & Reset entire day queue
        $this->postJson('/api/v1/center/queue/complete', [
            'group_id' => $g1['id'],
            'slot_id' => $g1['scheduleSlots'][0]['id'],
        ])->assertOk();

        $this->postJson('/api/v1/center/queue/reset', ['day' => 'saturday'])
            ->assertOk()
            ->assertJsonPath('data.resetCount', 1);
    }
}
