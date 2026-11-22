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
}
