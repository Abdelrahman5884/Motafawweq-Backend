<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_a_superadmin_interactively_and_audits_without_leaking_password(): void
    {
        $secret = 'UltraStrong#Admin2027!';

        $this->artisan('admin:provision')
            ->expectsQuestion('SuperAdmin Email', 'root@motafawweq.me')
            ->expectsQuestion('SuperAdmin Display Name', 'SuperAdmin Root')
            ->expectsQuestion('SuperAdmin Password (hidden input, min 12 chars)', $secret)
            ->expectsQuestion('Confirm SuperAdmin Password', $secret)
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'root@motafawweq.me')->first();

        $this->assertNotNull($admin);
        $this->assertSame(UserRole::ADMIN, $admin->role);
        $this->assertSame(UserStatus::ACTIVE, $admin->status);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check($secret, $admin->password));

        $audit = SecurityAuditLog::query()->where('event', 'cli.superadmin.created')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($secret, json_encode($audit->context));
    }

    public function test_it_rejects_weak_passwords_in_cli(): void
    {
        $this->artisan('admin:provision')
            ->expectsQuestion('SuperAdmin Email', 'weak@motafawweq.me')
            ->expectsQuestion('SuperAdmin Display Name', 'Weak Admin')
            ->expectsQuestion('SuperAdmin Password (hidden input, min 12 chars)', 'password123')
            ->expectsQuestion('Confirm SuperAdmin Password', 'password123')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'weak@motafawweq.me']);
    }

    public function test_it_rotates_existing_admin_revokes_tokens_and_removes_legacy_demo_admin(): void
    {
        $legacy = User::factory()->create([
            'email' => 'hq@motafawweq.me',
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $legacy->createToken('legacy-token', ['access']);

        $existing = User::factory()->create([
            'email' => 'ops@motafawweq.me',
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $existing->createToken('old-session', ['access']);

        $newSecret = 'Rotated#Vault2027!';

        $this->artisan('admin:provision', ['--revoke-demo-admin' => true])
            ->expectsQuestion('SuperAdmin Email', 'ops@motafawweq.me')
            ->expectsQuestion('SuperAdmin Display Name', 'Platform Ops')
            ->expectsQuestion('SuperAdmin Password (hidden input, min 12 chars)', $newSecret)
            ->expectsQuestion('Confirm SuperAdmin Password', $newSecret)
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => 'hq@motafawweq.me']);
        $this->assertSame(0, $existing->fresh()->tokens()->count());
        $this->assertTrue(Hash::check($newSecret, $existing->fresh()->password));
    }
}
