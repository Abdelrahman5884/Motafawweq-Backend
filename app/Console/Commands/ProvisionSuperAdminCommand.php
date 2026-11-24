<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Services\V1\Auth\TokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class ProvisionSuperAdminCommand extends Command
{
    /**
     * SECURITY DESIGN:
     * - Password is NEVER accepted as a CLI argument (avoids shell history & `ps` process list exposure).
     * - Interactive mode uses masked terminal input (`$this->secret()`).
     * - Non-interactive mode reads ephemeral env vars (`SUPERADMIN_EMAIL`, `SUPERADMIN_PASSWORD`, `SUPERADMIN_NAME`)
     *   via `--from-env`, so credentials never live in source code, seeders, or command arguments.
     *
     * @var string
     */
    protected $signature = 'admin:provision
        {--email= : SuperAdmin email address (prompts interactively if omitted)}
        {--name= : SuperAdmin display name (defaults to Motafawweq SuperAdmin)}
        {--from-env : Read credentials securely from SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD environment variables}
        {--revoke-demo-admin : Revoke tokens and remove any legacy seeded demo admin account (hq@motafawweq.me)}';

    /**
     * @var string
     */
    protected $description = 'Securely provision or rotate a Platform SuperAdmin account directly from the terminal.';

    public function handle(TokenService $tokenService): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>╔══════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=cyan;options=bold>║   Motafawweq — High-Security SuperAdmin CLI Provisioner  ║</>');
        $this->line('<fg=cyan;options=bold>╚══════════════════════════════════════════════════════════╝</>');
        $this->newLine();

        $fromEnv = (bool) $this->option('from-env');

        if ($fromEnv) {
            $email = strtolower(trim((string) env('SUPERADMIN_EMAIL', '')));
            $password = (string) env('SUPERADMIN_PASSWORD', '');
            $name = trim((string) env('SUPERADMIN_NAME', '')) ?: 'Abdelrhaman Hassan';
        } else {
            $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('SuperAdmin Email'))));
            $name = trim((string) ($this->option('name') ?: $this->ask('SuperAdmin Display Name', 'Abdelrhaman Hassan')));
            $password = (string) $this->secret('SuperAdmin Password (hidden input, min 12 chars)');
            $confirmation = (string) $this->secret('Confirm SuperAdmin Password');

            if (! hash_equals($password, $confirmation)) {
                $this->error('Password confirmation does not match. Aborting.');

                return self::FAILURE;
            }
        }

        // Strict validation: email format + high-entropy password policy
        $validator = Validator::make(
            [
                'email' => $email,
                'name' => $name,
                'password' => $password,
            ],
            [
                'email' => ['required', 'string', 'email:rfc', 'max:190'],
                'name' => ['required', 'string', 'min:2', 'max:150'],
                'password' => [
                    'required',
                    'string',
                    Password::min(12)->mixedCase()->numbers()->symbols(),
                ],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error("• {$error}");
            }

            return self::FAILURE;
        }

        $action = 'created';
        $revokedDemoCount = 0;

        /** @var User $admin */
        $admin = DB::transaction(function () use ($email, $name, $password, $tokenService, &$action, &$revokedDemoCount): User {
            $existing = User::query()->where('email', $email)->first();

            if ($existing) {
                $action = 'rotated';
                $tokenService->revokeAll($existing);
                $existing->forceFill([
                    'name' => $name,
                    'password' => $password, // Hashed via model cast
                    'role' => UserRole::ADMIN->value,
                    'status' => UserStatus::ACTIVE->value,
                    'email_verified_at' => $existing->email_verified_at ?? now(),
                    'password_changed_at' => now(),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
                $user = $existing->refresh();
            } else {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password, // Hashed via model cast
                    'role' => UserRole::ADMIN->value,
                    'status' => UserStatus::ACTIVE->value,
                    'email_verified_at' => now(),
                    'password_changed_at' => now(),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
            }

            // Optional cleanup of legacy demo admin account so zero weak admin accounts remain
            if ($this->option('revoke-demo-admin')) {
                $legacyAdmins = User::query()
                    ->where('role', UserRole::ADMIN->value)
                    ->where('email', 'hq@motafawweq.me')
                    ->where('id', '!=', $user->id)
                    ->get();

                foreach ($legacyAdmins as $legacy) {
                    $tokenService->revokeAll($legacy);
                    $legacy->delete();
                    $revokedDemoCount++;
                }
            }

            // Immutable security audit log (NEVER logs plaintext password)
            SecurityAuditLog::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'event' => "cli.superadmin.{$action}",
                'level' => 'warning',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'ArtisanCLI/admin:provision',
                'context' => [
                    'email' => $user->email,
                    'action' => $action,
                    'revoked_legacy_demo_admins' => $revokedDemoCount,
                ],
                'created_at' => now(),
            ]);

            return $user;
        });

        $this->info("✔ SuperAdmin account {$action} successfully (ID #{$admin->id}, Email: {$admin->email}).");
        if ($revokedDemoCount > 0) {
            $this->warn("✔ Revoked and removed {$revokedDemoCount} legacy demo admin account(s).");
        }
        $this->line('<fg=gray>All previous sessions for this account were revoked. Credentials were never written to disk or logs.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
