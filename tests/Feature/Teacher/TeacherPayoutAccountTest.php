<?php

namespace Tests\Feature\Teacher;

use App\Enums\PayoutAccountType;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherPayoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedTeacher(string $email = 'payout.teacher@motafawweq.com', ?string $phone = null): array
    {
        $teacher = User::factory()->create([
            'name'              => 'أ. طارق منصور',
            'email'             => $email,
            'phone'             => $phone ?? '010' . random_int(10000000, 99999999),
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $token = $teacher->createToken('test-device', [TokenType::ACCESS->value])->plainTextToken;

        return [$teacher, $token];
    }

    public function test_teacher_can_list_default_seeded_payout_accounts(): void
    {
        [, $token] = $this->createAuthenticatedTeacher();

        $response = $this->withToken($token)
            ->getJson('/api/v1/teacher/payout-accounts');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'instapay')
            ->assertJsonPath('data.0.isDefault', true)
            ->assertJsonPath('data.1.type', 'vodafone');
    }

    public function test_creating_payout_account_encrypts_identifier_at_rest_and_masks_audit_log(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $rawIban = 'EG890044556677889900112233445';

        $response = $this->withToken($token)
            ->postJson('/api/v1/teacher/payout-accounts', [
                'type'          => 'cib',
                'labelAr'       => 'حساب البنك التجاري الدولي CIB',
                'accountNumber' => $rawIban,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'cib')
            ->assertJsonPath('data.accountNumber', $rawIban)
            ->assertJsonPath('data.isDefault', true);

        $uuid = $response->json('data.id');

        // Verify at-rest encryption in raw database table (must NOT be plaintext)
        $rawCiphertext = DB::table('teacher_payout_accounts')
            ->where('uuid', $uuid)
            ->value('account_identifier');

        $this->assertNotNull($rawCiphertext);
        $this->assertNotEquals($rawIban, $rawCiphertext);
        $this->assertStringStartsWith('eyJpdiI6', $rawCiphertext);

        // Verify security audit log never stores raw IBAN, only masked representation
        $audit = SecurityAuditLog::where('user_id', $teacher->id)
            ->where('event', 'teacher.payout_account.created')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertNotEquals($rawIban, $audit->context['masked_identifier'] ?? '');
        $this->assertStringContainsString('***', $audit->context['masked_identifier'] ?? '');
    }

    public function test_teacher_can_toggle_active_and_switch_default_payout_account_atomically(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        // Seed 2 accounts
        $this->withToken($token)->getJson('/api/v1/teacher/payout-accounts')->assertOk();

        $accounts = TeacherPayoutAccount::forTeacher($teacher->id)->orderBy('id')->get();
        $first = $accounts[0];  // default = true
        $second = $accounts[1]; // default = false

        // Toggle active off on second account
        $toggleRes = $this->withToken($token)
            ->patchJson("/api/v1/teacher/payout-accounts/{$second->uuid}/toggle-active");

        $toggleRes->assertOk()
            ->assertJsonPath('data.isActive', false);

        // Promote second account to default (must also re-enable isActive and unset first account's isDefault)
        $defaultRes = $this->withToken($token)
            ->patchJson("/api/v1/teacher/payout-accounts/{$second->uuid}/set-default");

        $defaultRes->assertOk()
            ->assertJsonPath('data.isDefault', true)
            ->assertJsonPath('data.isActive', true);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_deleting_default_account_promotes_fallback_account_to_default(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        $this->withToken($token)->getJson('/api/v1/teacher/payout-accounts')->assertOk();

        $accounts = TeacherPayoutAccount::forTeacher($teacher->id)->orderBy('id')->get();
        $defaultAccount = $accounts->firstWhere('is_default', true);
        $fallbackAccount = $accounts->firstWhere('is_default', false);

        $deleteRes = $this->withToken($token)
            ->deleteJson("/api/v1/teacher/payout-accounts/{$defaultAccount->uuid}");

        $deleteRes->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('teacher_payout_accounts', ['id' => $defaultAccount->id]);
        $this->assertTrue($fallbackAccount->fresh()->is_default);
    }

    public function test_cross_teacher_idor_manipulation_on_payout_accounts_is_blocked_and_logged(): void
    {
        [$teacherA] = $this->createAuthenticatedTeacher('teacher.a@motafawweq.com');
        [$teacherB, $tokenB] = $this->createAuthenticatedTeacher('teacher.b@motafawweq.com');

        $accountA = TeacherPayoutAccount::create([
            'teacher_id'         => $teacherA->id,
            'type'               => PayoutAccountType::INSTAPAY,
            'label_ar'           => 'إنستاباي المعلم أ',
            'label_en'           => 'Teacher A InstaPay',
            'account_identifier' => 'teacher.a@instapay',
            'is_default'         => true,
            'is_active'          => true,
        ]);

        // Teacher B attempts to set Teacher A's account as default
        $this->withToken($tokenB)
            ->patchJson("/api/v1/teacher/payout-accounts/{$accountA->uuid}/set-default")
            ->assertStatus(403);

        // Teacher B attempts to delete Teacher A's account
        $this->withToken($tokenB)
            ->deleteJson("/api/v1/teacher/payout-accounts/{$accountA->uuid}")
            ->assertStatus(403);

        // Verify accountA still exists and IDOR attempt was logged in security_audit_logs
        $this->assertDatabaseHas('teacher_payout_accounts', ['id' => $accountA->id]);
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}
