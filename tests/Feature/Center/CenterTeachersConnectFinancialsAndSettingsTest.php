<?php

namespace Tests\Feature\Center;

use App\Enums\CenterPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\CenterSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CenterTeachersConnectFinancialsAndSettingsTest extends TestCase
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
            'name_ar' => 'أكاديمية الرواد',
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

    public function test_teachers_network_invite_rotation_and_motafawweq_connect(): void
    {
        $center = $this->createProCenter();

        $platformTeacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'name' => 'أ. خالد منتصر فيزياء',
            'phone' => '01234567890',
            'email' => 'khaled.phys@motafawweq.test',
        ]);

        // Admin must NEVER appear in Motafawweq Connect search!
        User::factory()->admin()->create([
            'name' => 'خالد أدمن سري',
            'email' => 'khaled.admin@motafawweq.test',
        ]);

        Sanctum::actingAs($center, ['access']);

        $branchId = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع الدقي'])->json('data.id');

        // Create teacher & check cryptographic activation link returned (token hash hidden)
        $created = $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'د. إيمان الشريف',
            'subject_ar' => 'الكيمياء',
            'email' => 'iman@center.test',
            'phone' => '01099887766',
            'branch_id' => $branchId,
            'settlement_rate' => 0.70,
        ])->assertStatus(201)->json('data');

        $this->assertNotEmpty($created['activationLink']);
        $this->assertArrayNotHasKey('invite_token_hash', $created);

        // Duplicate email within same center rejected
        $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'نسخة مكررة',
            'subject_ar' => 'كيمياء',
            'email' => 'IMAN@center.test',
        ])->assertStatus(422);

        // Resend invite rotates activation link
        $resent = $this->postJson("/api/v1/center/teachers/{$created['id']}/resend-invite")
            ->assertOk()
            ->json('data');
        $this->assertNotEquals($created['activationLink'], $resent['activationLink']);

        // Motafawweq Connect search finds active teacher, excludes admin, enforces min 2 chars
        $this->getJson('/api/v1/center/connect/search?q=a')->assertStatus(422);

        $searchRes = $this->getJson('/api/v1/center/connect/search?q=خالد')->assertOk();
        $searchIds = collect($searchRes->json('data'))->pluck('id')->all();
        $this->assertContains($platformTeacher->id, $searchIds);
        $this->assertCount(1, $searchIds);

        // Connect platform teacher -> automatically adds them to CenterTeacher roster
        $conn = $this->postJson('/api/v1/center/connect/users', [
            'user_id' => $platformTeacher->id,
            'branch_id' => $branchId,
        ])->assertStatus(201)->json('data');

        // Duplicate connection prevented
        $this->postJson('/api/v1/center/connect/users', [
            'user_id' => $platformTeacher->id,
            'branch_id' => $branchId,
        ])->assertStatus(422);

        $this->getJson('/api/v1/center/teachers')->assertOk()->assertJsonCount(2, 'data');

        // Disconnect user
        $this->deleteJson("/api/v1/center/connect/users/{$conn['id']}")->assertOk();
    }

    public function test_financials_pl_expenses_teacher_settlements_and_outstanding_dues(): void
    {
        $center = $this->createProCenter();
        Sanctum::actingAs($center, ['access']);

        $branchId = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع الدقي'])->json('data.id');
        $teacher = $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'د. سلمى السيد',
            'subject_ar' => 'الأحياء',
            'email' => 'salma@fin.test',
            'branch_id' => $branchId,
            'settlement_rate' => 0.75,
        ])->json('data');

        $group = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة الأحياء المالية',
            'subject_ar' => 'الأحياء',
            'branch_id' => $branchId,
            'center_teacher_id' => $teacher['id'],
            'price_egp' => 1000,
        ])->json('data');

        // Enroll 2 students: Student 1 paid 1000, Student 2 has 400 due (paid 600) => Gross collected = 1600
        $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'name_ar' => 'طالب مسدد',
            'phone' => '01011112222',
            'payment_status' => 'paid',
        ])->assertStatus(201);

        $dueStudent = $this->postJson("/api/v1/center/groups/{$group['id']}/students", [
            'name_ar' => 'طالب متأخر',
            'phone' => '01033334444',
            'payment_status' => 'partial',
            'due_amount_egp' => 400,
        ])->assertStatus(201)->json('data');

        // Log operating expense of 150 EGP
        $exp = $this->postJson('/api/v1/center/financials/expenses', [
            'title_ar' => 'فاتورة كهرباء الدقي',
            'category' => 'utilities',
            'amount_egp' => 150,
            'branch_id' => $branchId,
        ])->assertStatus(201)->json('data');

        // Verify P&L math:
        // Gross = 1600, Teacher 75% = 1200, Center Net = 400, Expenses = 150, Net Profit = 250, Dues = 400
        $this->getJson('/api/v1/center/financials/overview')
            ->assertOk()
            ->assertJsonPath('data.summary.grossCollectedRevenue', 1600)
            ->assertJsonPath('data.summary.teacherPayouts', 1200)
            ->assertJsonPath('data.summary.centerNetRevenue', 400)
            ->assertJsonPath('data.summary.totalExpenses', 150)
            ->assertJsonPath('data.summary.netProfit', 250)
            ->assertJsonPath('data.summary.outstandingReceivables', 400);

        // Automated teacher settlement + atomic approval
        $this->getJson('/api/v1/center/financials/settlements')
            ->assertOk()
            ->assertJsonPath('data.0.grossCollectedEgp', 1600)
            ->assertJsonPath('data.0.teacherShareEgp', 1200)
            ->assertJsonPath('data.0.centerShareEgp', 400)
            ->assertJsonPath('data.0.status', 'pending');

        $this->postJson("/api/v1/center/financials/settlements/{$teacher['id']}/approve", [
            'reference_note' => 'تحويل إنستاباي رقم 9981',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        // Outstanding dues -> remind -> mark paid
        $this->getJson('/api/v1/center/financials/dues')
            ->assertOk()
            ->assertJsonPath('data.totalOutstandingEgp', 400)
            ->assertJsonPath('data.count', 1);

        $this->postJson("/api/v1/center/financials/dues/{$dueStudent['enrollmentId']}/remind")->assertOk();
        $this->postJson("/api/v1/center/financials/dues/{$dueStudent['enrollmentId']}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.dueAmountEgp', 0);

        $this->getJson('/api/v1/center/financials/dues')
            ->assertOk()
            ->assertJsonPath('data.totalOutstandingEgp', 0);

        // Export & delete expense
        $this->get('/api/v1/center/financials/expenses/export')->assertOk();
        $this->deleteJson("/api/v1/center/financials/expenses/{$exp['id']}")->assertOk();
    }

    public function test_settings_kpis_tasks_announcements_batch_import_and_cloud_backups(): void
    {
        $center = $this->createProCenter();
        Sanctum::actingAs($center, ['access']);

        // 1. Settings & KPIs
        $this->putJson('/api/v1/center/settings', [
            'centerNameAr' => 'أكاديمية النخبة المتفوقة',
            'lateMarginMinutes' => 20,
            'siblingDiscountPercent' => 15,
            'refundPolicy' => 'replacement',
            'mandatoryCashClose' => true,
            'paymentMethods' => ['cash' => true, 'vodafoneCash' => true, 'instaPay' => true, 'visa' => false],
        ])->assertOk()
            ->assertJsonPath('data.centerNameAr', 'أكاديمية النخبة المتفوقة')
            ->assertJsonPath('data.lateMarginMinutes', 20)
            ->assertJsonPath('data.siblingDiscountPercent', 15)
            ->assertJsonPath('data.refundPolicy', 'replacement');

        $this->putJson('/api/v1/center/settings/kpis', [
            'targetAttendance' => 92,
            'targetRevenue' => 450000,
            'targetUtilization' => 88,
        ])->assertOk()
            ->assertJsonPath('data.kpiTargets.targetAttendance', 92)
            ->assertJsonPath('data.kpiTargets.targetRevenue', 450000)
            ->assertJsonPath('data.kpiTargets.targetUtilization', 88);

        // 2. Tasks CRUD & toggle
        $task = $this->postJson('/api/v1/center/tasks', [
            'titleAr' => 'مراجعة كشوفات الحضور',
            'assignee' => 'أ. ياسمين نبيل',
            'deadline' => 'اليوم 04:00 م',
        ])->assertStatus(201)->json('data');

        $this->patchJson("/api/v1/center/tasks/{$task['id']}/toggle")
            ->assertOk()
            ->assertJsonPath('data.completed', true);

        $this->putJson("/api/v1/center/tasks/{$task['id']}", ['titleAr' => 'مراجعة محدثة'])
            ->assertOk()
            ->assertJsonPath('data.titleAr', 'مراجعة محدثة');

        $this->deleteJson("/api/v1/center/tasks/{$task['id']}")->assertOk();

        // 3. Internal Announcements CRUD
        $ann = $this->postJson('/api/v1/center/announcements', [
            'titleAr' => 'تعميم بوابة الباركود',
            'contentAr' => 'يرجى مسح بطاقات جميع الطلاب عند الدخول.',
            'branch' => 'جميع الفروع',
        ])->assertStatus(201)->json('data');

        $this->getJson('/api/v1/center/announcements')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/center/announcements/{$ann['id']}")->assertOk();

        // 4. Batch Student Import with deduplication
        $group = $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'فيزياء الثانوية العامة',
            'subject_ar' => 'الفيزياء',
        ])->assertStatus(201)->json('data');

        $this->get('/api/v1/center/import/template')->assertOk();

        $importRes = $this->postJson('/api/v1/center/import/students', [
            'default_group_id' => $group['id'],
            'rows' => [
                ['name' => 'حازم خالد', 'phone' => '01019283746', 'grade' => '3 ثانوي'],
                ['name' => 'حازم مكرر', 'phone' => '01019283746', 'grade' => '3 ثانوي'], // duplicate skipped
                ['name' => 'رنا إبراهيم', 'phone' => '01128374655', 'grade' => '3 ثانوي'],
            ],
        ])->assertOk();

        $importRes->assertJsonPath('data.importedCount', 2)
            ->assertJsonPath('data.skippedCount', 1);

        // 5. Pro Cloud Backup Snapshot with SHA-256 checksum
        $backup = $this->postJson('/api/v1/center/backups')->assertStatus(201)->json('data');
        $this->assertEquals(64, strlen($backup['checksumSha256']));
        $this->assertEquals(1, $backup['recordsSummary']['groups']);
        $this->assertEquals(2, $backup['recordsSummary']['enrollments']);

        $this->getJson('/api/v1/center/backups')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_center_profile_manager_name_avatar_lifecycle_and_account_deletion(): void
    {
        Storage::fake('public');

        $center = $this->createProCenter();
        $center->forceFill([
            'name' => 'أ. طارق منصور',
            'password' => 'CenterPass@2026!',
        ])->save();
        Sanctum::actingAs($center, ['access']);

        // 1. Update center identity, manager name & preferences
        $updated = $this->putJson('/api/v1/center/settings', [
            'name_ar' => 'سنتر النخبة التعليمي',
            'name_en' => 'Elite Educational Center',
            'manager_name' => 'م. طارق منصور',
            'phone' => '01099887766',
            'working_hours' => '09:00 ص - 09:00 م',
            'address_ar' => 'الدقي، الجيزة',
            'preferred_theme' => 'dark',
            'preferred_lang' => 'ar',
        ])->assertOk();

        $updated->assertJsonPath('data.centerNameAr', 'سنتر النخبة التعليمي')
            ->assertJsonPath('data.managerName', 'م. طارق منصور')
            ->assertJsonPath('data.phone', '01099887766')
            ->assertJsonPath('data.preferredTheme', 'dark');

        $this->assertSame('م. طارق منصور', $center->fresh()->name);
        $this->assertSame('01099887766', $center->fresh()->phone);

        // 2. Upload & Delete Center Avatar (real 1x1 PNG magic bytes)
        $pngBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmpFile = UploadedFile::fake()->createWithContent('center-logo.png', $pngBinary);

        $avatarRes = $this->post('/api/v1/center/settings/avatar', [
            'avatar' => $tmpFile,
        ], ['Accept' => 'application/json'])->assertOk();

        $avatarUrl = $avatarRes->json('data.avatar');
        $this->assertNotEmpty($avatarUrl);
        $this->assertSame($avatarUrl, $center->fresh()->avatar);

        $deleteAvatarRes = $this->deleteJson('/api/v1/center/settings/avatar')->assertOk();
        $this->assertNull($deleteAvatarRes->json('data.avatar'));
        $this->assertNull($center->fresh()->avatar);

        // 3. Permanent Center Account Deletion (DELETE /api/v1/auth/account)
        $centerId = $center->id;
        $this->deleteJson('/api/v1/auth/account', [
            'password' => 'WrongPass@123',
        ])->assertStatus(422);

        $this->deleteJson('/api/v1/auth/account', [
            'password' => 'CenterPass@2026!',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $centerId]);
        $this->assertDatabaseMissing('center_profiles', ['user_id' => $centerId]);
    }

    public function test_platform_teacher_invite_requires_teacher_approval_before_activation(): void
    {
        $center = $this->createProCenter();

        $platformTeacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'name' => 'د. شريف المنياوي',
            'email' => 'sherif.platform@motafawweq.test',
            'phone' => '01055443322',
        ]);

        $otherTeacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'name' => 'أ. معلم آخر',
        ]);

        Sanctum::actingAs($center, ['access']);
        $branchId = $this->postJson('/api/v1/center/branches', ['name_ar' => 'فرع مدينة نصر'])->json('data.id');

        // 1. Center invites platform teacher via Connect -> status MUST be 'invited' (awaiting teacher approval)
        $conn = $this->postJson('/api/v1/center/connect/users', [
            'user_id' => $platformTeacher->id,
            'branch_id' => $branchId,
        ])->assertStatus(201)->json('data');

        $this->assertSame('pending', $conn['status']);
        $this->assertTrue($conn['awaitingTeacherApproval']);

        $teacherUuid = $conn['centerTeacherId'];
        $this->assertNotEmpty($teacherUuid);

        // Center MUST NOT be able to force-activate an unaccepted platform teacher
        $this->putJson("/api/v1/center/teachers/{$teacherUuid}", [
            'status' => 'active',
        ])->assertStatus(422);

        // Center MUST NOT be able to assign a study group to an unaccepted platform teacher
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة الفيزياء التجريبية',
            'subject_ar' => 'الفيزياء',
            'center_teacher_id' => $teacherUuid,
        ])->assertStatus(422);

        // 2. Another teacher cannot accept/decline this invitation (strict IDOR isolation)
        Sanctum::actingAs($otherTeacher, ['access']);
        $this->postJson("/api/v1/teacher/center-invitations/{$teacherUuid}/accept")->assertStatus(404);
        $this->postJson("/api/v1/teacher/center-invitations/{$teacherUuid}/decline")->assertStatus(404);

        // 3. Invited platform teacher sees the incoming invitation and declines it first
        Sanctum::actingAs($platformTeacher, ['access']);
        $listRes = $this->getJson('/api/v1/teacher/center-invitations')->assertOk();
        $listRes->assertJsonCount(1, 'data.invitations')
            ->assertJsonPath('data.invitations.0.id', $teacherUuid)
            ->assertJsonPath('data.invitations.0.centerNameAr', 'أكاديمية الرواد')
            ->assertJsonPath('data.invitations.0.branchNameAr', 'فرع مدينة نصر');

        $this->postJson("/api/v1/teacher/center-invitations/{$teacherUuid}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        // 4. Center sees declined status, resends invite -> moves back to 'invited'
        Sanctum::actingAs($center, ['access']);
        $this->getJson("/api/v1/center/teachers/{$teacherUuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->postJson("/api/v1/center/teachers/{$teacherUuid}/resend-invite")
            ->assertOk()
            ->assertJsonPath('data.status', 'invited');

        // 5. Platform teacher accepts the invitation -> becomes active!
        Sanctum::actingAs($platformTeacher, ['access']);
        $this->postJson("/api/v1/teacher/center-invitations/{$teacherUuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/teacher/center-invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data.invitations')
            ->assertJsonCount(1, 'data.activeCenters');

        // 6. Center can now assign a study group to the accepted platform teacher
        Sanctum::actingAs($center, ['access']);
        $this->postJson('/api/v1/center/groups', [
            'name_ar' => 'مجموعة الفيزياء المعتمدة',
            'subject_ar' => 'الفيزياء',
            'center_teacher_id' => $teacherUuid,
        ])->assertStatus(201);

        // 7. Adding another existing platform teacher via POST /center/teachers also starts as 'invited'
        $platformTeacher2 = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'name' => 'أ. مروة كامل',
            'email' => 'marwa.kamel@motafawweq.test',
        ]);

        $directAdd = $this->postJson('/api/v1/center/teachers', [
            'name_ar' => 'أ. مروة كامل',
            'subject_ar' => 'الأحياء',
            'email' => 'marwa.kamel@motafawweq.test',
            'branch_id' => $branchId,
        ])->assertStatus(201)->json('data');

        $this->assertSame('invited', $directAdd['status']);
        $this->assertTrue($directAdd['awaitingTeacherApproval']);
    }
}
