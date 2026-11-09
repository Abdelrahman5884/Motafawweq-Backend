<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Mail\Transports\BrevoApiTransport;
use App\Models\User;
use App\Notifications\Auth\NewDeviceLoginNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordOtpNotification;
use App\Notifications\Auth\VerifyEmailOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG_PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /* ----------------------------------------------------------------- */
    /* Helpers                                                            */
    /* ----------------------------------------------------------------- */

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'email'    => 'user@motafawweq.test',
            'password' => self::STRONG_PASSWORD,
        ], $attrs));
    }

    private function login(string $email = 'user@motafawweq.test', string $password = self::STRONG_PASSWORD, string $ua = 'PHPUnit')
    {
        return $this->withHeader('User-Agent', $ua)
            ->postJson('/api/v1/auth/login', compact('email', 'password'));
    }

    private function tokensFor(User $user, string $ua = 'PHPUnit'): array
    {
        return $this->login($user->email, self::STRONG_PASSWORD, $ua)->json('data.tokens');
    }

    private function bearer(string $token): static
    {
        // Each call must re-resolve the guard, otherwise the previous user stays cached
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function sentCode(User $user, string $notificationClass): string
    {
        $code = null;
        Notification::assertSentTo($user, $notificationClass, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });
        return $code;
    }

    /* ----------------------------------------------------------------- */
    /* Registration                                                       */
    /* ----------------------------------------------------------------- */

    public function test_register_returns_token_pair_and_sends_verification_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'أحمد محمد',
            'email'                 => 'Ahmed@Motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ahmed@motafawweq.test')
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token', 'token_type', 'expires_in']]]);

        $user = User::where('email', 'ahmed@motafawweq.test')->first();
        Notification::assertSentTo($user, VerifyEmailOtpNotification::class);
        $this->assertDatabaseMissing('verification_codes', ['code_hash' => $this->sentCode($user, VerifyEmailOtpNotification::class)]);
    }

    public function test_cannot_self_register_as_admin(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Hacker',
            'email'                 => 'hacker@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'hacker@motafawweq.test']);
    }

    public function test_mass_assignment_of_status_is_ignored(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Sneaky User',
            'email'                 => 'sneaky@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'teacher',
            'status'                => 'suspended',
            'email_verified_at'     => now()->toDateTimeString(),
        ])->assertCreated();

        $user = User::where('email', 'sneaky@motafawweq.test')->first();
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertNull($user->email_verified_at);
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Weak User',
            'email'                 => 'weak@motafawweq.test',
            'password'              => 'password',
            'password_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_passwords_with_html_characters_are_not_altered(): void
    {
        $password = '<b>Ab1!xyz</b> ';
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Html Pass',
            'email'                 => 'html@motafawweq.test',
            'password'              => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        $this->login('html@motafawweq.test', $password)->assertOk();
        $this->login('html@motafawweq.test', 'Ab1!xyz')->assertStatus(401);
    }

    /* ----------------------------------------------------------------- */
    /* Login, lockout, enumeration                                        */
    /* ----------------------------------------------------------------- */

    public function test_login_success_records_history(): void
    {
        $user = $this->makeUser();

        $this->login()->assertOk()
            ->assertJsonStructure(['data' => ['user', 'tokens' => ['access_token', 'refresh_token']]]);

        $this->assertDatabaseHas('login_histories', ['user_id' => $user->id, 'status' => 'success']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_and_unknown_email_return_identical_response(): void
    {
        $this->makeUser();

        $wrongPass = $this->login('user@motafawweq.test', 'Wrong!Pass123');
        $unknown = $this->login('ghost@motafawweq.test', 'Wrong!Pass123');

        $wrongPass->assertStatus(401);
        $unknown->assertStatus(401);
        $this->assertSame($wrongPass->json(), $unknown->json());
    }

    public function test_account_locks_after_repeated_failures_even_with_correct_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser();

        for ($i = 0; $i < 4; $i++) {
            $this->login('user@motafawweq.test', 'Wrong!Pass123')->assertStatus(401);
        }
        $this->login('user@motafawweq.test', 'Wrong!Pass123')->assertStatus(423)
            ->assertJsonPath('error_code', 'ACCOUNT_LOCKED');

        // Correct password is refused while locked
        $this->login()->assertStatus(423);
        $this->assertTrue($user->fresh()->isLocked());

        // After the lock window it works again and counters reset
        $this->travel(16)->minutes();
        $this->login()->assertOk();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->login('user@motafawweq.test', 'Wrong!Pass123');
        }

        $this->login('user@motafawweq.test', 'Wrong!Pass123')->assertStatus(429);
    }

    public function test_suspended_user_cannot_login(): void
    {
        $this->makeUser(['status' => UserStatus::SUSPENDED]);

        $this->login()->assertStatus(403)->assertJsonPath('error_code', 'ACCOUNT_SUSPENDED');
    }

    public function test_suspension_takes_effect_immediately_on_existing_tokens(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);

        $user->forceFill(['status' => UserStatus::SUSPENDED])->save();

        $this->bearer($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->assertSame(0, $user->tokens()->count());
    }

    /* ----------------------------------------------------------------- */
    /* Token types, refresh rotation, logout                              */
    /* ----------------------------------------------------------------- */

    public function test_refresh_token_cannot_be_used_as_access_token(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->bearer($tokens['refresh_token'])->getJson('/api/v1/auth/me')
            ->assertStatus(401)->assertJsonPath('error_code', 'INVALID_TOKEN_TYPE');
    }

    public function test_access_token_cannot_be_used_to_refresh(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->bearer($tokens['access_token'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }

    public function test_refresh_rotates_tokens_and_old_refresh_token_is_single_use(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $new = $this->bearer($tokens['refresh_token'])->postJson('/api/v1/auth/refresh')
            ->assertOk()->json('data');

        $this->assertNotSame($tokens['access_token'], $new['access_token']);

        // Old pair is dead
        $this->bearer($tokens['refresh_token'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
        $this->bearer($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);

        // New pair works
        $this->bearer($new['access_token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_access_token_expires(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->travel(61)->minutes();

        $this->bearer($tokens['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->bearer($tokens['refresh_token'])->postJson('/api/v1/auth/refresh')->assertOk();
    }

    public function test_logout_revokes_access_and_refresh_of_that_device_only(): void
    {
        $user = $this->makeUser();
        $phone = $this->tokensFor($user, 'Phone');
        $laptop = $this->tokensFor($user, 'Laptop');

        $this->bearer($phone['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->bearer($phone['refresh_token'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
        $this->bearer($laptop['access_token'])->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_logout_all_revokes_every_device(): void
    {
        $user = $this->makeUser();
        $phone = $this->tokensFor($user, 'Phone');
        $this->tokensFor($user, 'Laptop');

        $this->bearer($phone['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_concurrent_session_limit_evicts_oldest_device(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['auth_security.tokens.max_sessions' => 2]);
        $user = $this->makeUser();

        $first = $this->tokensFor($user, 'Device-1');
        $this->tokensFor($user, 'Device-2');
        $this->tokensFor($user, 'Device-3');

        $this->bearer($first['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertSame(4, $user->tokens()->count()); // 2 sessions x (access + refresh)
    }

    /* ----------------------------------------------------------------- */
    /* Email verification                                                 */
    /* ----------------------------------------------------------------- */

    public function test_unverified_user_is_blocked_until_email_is_verified_with_otp(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'New Student',
            'email'                 => 'new@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ]);
        $user = User::where('email', 'new@motafawweq.test')->first();
        $access = $this->tokensFor($user)['access_token'];

        $this->bearer($access)->getJson('/api/v1/auth/sessions')
            ->assertStatus(403)->assertJsonPath('error_code', 'EMAIL_NOT_VERIFIED');

        $code = $this->sentCode($user, VerifyEmailOtpNotification::class);
        $this->bearer($access)->postJson('/api/v1/auth/email/verify', ['code' => $code])
            ->assertOk()->assertJsonPath('data.email_verified', true);

        $this->bearer($access)->getJson('/api/v1/auth/sessions')->assertOk();

        // OTP is single use
        $this->bearer($access)->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertStatus(422);
    }

    public function test_otp_is_invalidated_after_max_wrong_attempts(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->makeUser(['email_verified_at' => null]);
        $access = $this->tokensFor($user)['access_token'];

        $this->bearer($access)->postJson('/api/v1/auth/email/resend')->assertOk();
        $code = $this->sentCode($user, VerifyEmailOtpNotification::class);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->bearer($access)->postJson('/api/v1/auth/email/verify', ['code' => $wrong])->assertStatus(422);
        }

        // Even the correct code is now dead
        $this->bearer($access)->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_otp_expires(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        $access = $this->tokensFor($user)['access_token'];
        $this->bearer($access)->postJson('/api/v1/auth/email/resend')->assertOk();
        $code = $this->sentCode($user, VerifyEmailOtpNotification::class);

        $this->travel(11)->minutes();
        $access = $this->tokensFor($user)['access_token']; // old access token expired? no (60m) but keep fresh

        $this->bearer($access)->postJson('/api/v1/auth/email/verify', ['code' => $code])->assertStatus(422);
    }

    public function test_otp_resend_has_cooldown(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);
        $access = $this->tokensFor($user)['access_token'];

        $this->bearer($access)->postJson('/api/v1/auth/email/resend')->assertOk();
        $this->bearer($access)->postJson('/api/v1/auth/email/resend')->assertStatus(429);
    }

    /* ----------------------------------------------------------------- */
    /* Password reset & change                                            */
    /* ----------------------------------------------------------------- */

    public function test_forgot_password_rejects_unregistered_email_and_verifies_otp_step(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@motafawweq.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'user@motafawweq.test'])
            ->assertOk()
            ->assertJsonPath('data', null);

        $code = $this->sentCode($user, ResetPasswordOtpNotification::class);
        $wrongCode = $code === '000000' ? '111111' : '000000';

        // Step 2: wrong OTP is rejected immediately
        $this->postJson('/api/v1/auth/password/verify-otp', [
            'email' => $user->email,
            'code'  => $wrongCode,
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        // Step 2: valid OTP is accepted without consuming it yet
        $this->postJson('/api/v1/auth/password/verify-otp', [
            'email' => $user->email,
            'code'  => $code,
        ])->assertOk();
    }

    public function test_full_password_reset_flow_revokes_sessions_and_unlocks_account(): void
    {
        $user = $this->makeUser();
        $old = $this->tokensFor($user);
        $user->forceFill(['locked_until' => now()->addMinutes(10)])->save();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $code = $this->sentCode($user, ResetPasswordOtpNotification::class);

        $newPassword = 'N3w!Secure#Pass';
        $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => $user->email,
            'code'                  => $code,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->bearer($old['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->login($user->email, self::STRONG_PASSWORD)->assertStatus(401);
        $this->login($user->email, $newPassword)->assertOk();
    }

    public function test_password_reset_with_wrong_code_fails(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email'                 => $user->email,
            'code'                  => '123456',
            'password'              => 'N3w!Secure#Pass',
            'password_confirmation' => 'N3w!Secure#Pass',
        ])->assertStatus(422);
    }

    public function test_change_password_keeps_current_device_and_kills_others(): void
    {
        $user = $this->makeUser();
        $current = $this->tokensFor($user, 'Current');
        $other = $this->tokensFor($user, 'Other');

        $this->bearer($current['access_token'])->postJson('/api/v1/auth/change-password', [
            'current_password'          => self::STRONG_PASSWORD,
            'new_password'              => 'An0ther!Strong1',
            'new_password_confirmation' => 'An0ther!Strong1',
        ])->assertOk();

        $this->bearer($current['access_token'])->getJson('/api/v1/auth/me')->assertOk();
        $this->bearer($other['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    /* ----------------------------------------------------------------- */
    /* Sessions                                                           */
    /* ----------------------------------------------------------------- */

    public function test_user_can_list_and_revoke_own_sessions_but_not_others(): void
    {
        $user = $this->makeUser();
        $current = $this->tokensFor($user, 'Current');
        $other = $this->tokensFor($user, 'Other');

        $sessions = $this->bearer($current['access_token'])->getJson('/api/v1/auth/sessions')
            ->assertOk()->json('data');
        $this->assertCount(2, $sessions);

        $otherSession = collect($sessions)->firstWhere('is_current', false)['id'];

        // Another user cannot revoke it (IDOR protection)
        $attacker = $this->makeUser(['email' => 'attacker@motafawweq.test']);
        $attackerToken = $this->tokensFor($attacker)['access_token'];
        $this->bearer($attackerToken)->deleteJson("/api/v1/auth/sessions/{$otherSession}")->assertNotFound();

        // Owner can
        $this->bearer($current['access_token'])->deleteJson("/api/v1/auth/sessions/{$otherSession}")->assertOk();
        $this->bearer($other['access_token'])->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_pending_teacher_cannot_access_protected_area(): void
    {
        $user = $this->makeUser(['status' => UserStatus::PENDING]);
        $access = $this->tokensFor($user)['access_token'];

        $this->bearer($access)->getJson('/api/v1/auth/me')->assertOk();
        $this->bearer($access)->getJson('/api/v1/auth/sessions')
            ->assertStatus(403)->assertJsonPath('error_code', 'ACCOUNT_PENDING');
    }

    public function test_new_user_does_not_require_supervisor_approval_and_activates_via_email_otp(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'أستاذ محمد',
            'email'                 => 'mohamed.teacher@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'teacher',
        ])->assertCreated();

        $token = $res->json('data.tokens.access_token');
        $user = User::where('email', 'mohamed.teacher@motafawweq.test')->first();

        // 1) Active right away, not pending supervisor approval
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertNull($user->email_verified_at);

        // 2) Protected route blocked by unverified email, NOT blocked by ACCOUNT_PENDING
        $this->bearer($token)->getJson('/api/v1/auth/sessions')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'EMAIL_NOT_VERIFIED');

        // 3) Confirm via email OTP message
        $otp = $this->sentCode($user, VerifyEmailOtpNotification::class);
        $this->bearer($token)->postJson('/api/v1/auth/email/verify', ['code' => $otp])->assertOk();

        // 4) Fully active & verified without any supervisor intervention
        $this->bearer($token)->getJson('/api/v1/auth/sessions')->assertOk();
    }

    public function test_auth_mail_notifications_implement_should_queue(): void
    {
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new VerifyEmailOtpNotification('123456', 10));
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new ResetPasswordOtpNotification('123456', 10));
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new NewDeviceLoginNotification('127.0.0.1', 'Mozilla/5.0'));
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new PasswordChangedNotification('127.0.0.1'));
    }

    /* ----------------------------------------------------------------- */
    /* Student Code & Parent-Student Linking                              */
    /* ----------------------------------------------------------------- */

    public function test_student_registration_generates_unique_student_code(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'عمر طارق',
            'email'                 => 'omar.student@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'student',
        ])->assertCreated();

        $code = $res->json('data.user.student_code');
        $this->assertNotEmpty($code);
        $this->assertMatchesRegularExpression('/^STD-[A-Z0-9]{6}$/', $code);
    }

    public function test_parent_registration_requires_valid_student_code_and_links_student(): void
    {
        $studentRes = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'عمر طارق',
            'email'                 => 'omar.child@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'student',
        ])->assertCreated();

        $studentCode = $studentRes->json('data.user.student_code');

        // 1) Missing student_code fails
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'طارق القاضي',
            'email'                 => 'parent1@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'parent',
        ])->assertStatus(422)->assertJsonValidationErrors('student_code');

        // 2) Invalid student_code fails
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'طارق القاضي',
            'email'                 => 'parent2@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'parent',
            'student_code'          => 'WRONG-9999',
        ])->assertStatus(422)->assertJsonValidationErrors('student_code');

        // 3) Valid student_code succeeds and links the child
        $parentRes = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'طارق القاضي',
            'email'                 => 'parent3@motafawweq.test',
            'password'              => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'role'                  => 'parent',
            'student_code'          => strtolower($studentCode),
        ])->assertCreated();

        $this->assertSame('parent', $parentRes->json('data.user.role'));
        $linkedChildren = $parentRes->json('data.user.linked_children');
        $this->assertCount(1, $linkedChildren);
        $this->assertSame($studentCode, $linkedChildren[0]['student_code']);
    }

    /* ----------------------------------------------------------------- */
    /* Bilingual Localization (ar / en) & Brevo API Transport             */
    /* ----------------------------------------------------------------- */

    public function test_validation_and_error_messages_respect_accept_language_header(): void
    {
        $arResponse = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/auth/login', ['email' => 'not-an-email', 'password' => '']);

        $arResponse->assertStatus(422);
        $this->assertStringContainsString('بيانات الإدخال غير صالحة', $arResponse->json('message'));
        $this->assertStringContainsString('البريد الإلكتروني', $arResponse->json('errors.email.0'));

        $enResponse = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/auth/login', ['email' => 'not-an-email', 'password' => '']);

        $enResponse->assertStatus(422);
        $this->assertStringContainsString('invalid', strtolower($enResponse->json('message')));
        $this->assertStringContainsString('email', strtolower($enResponse->json('errors.email.0')));
    }

    public function test_brevo_api_transport_sends_http_request_to_brevo_endpoint(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<2026@brevo.com>'], 201),
        ]);

        $transport = new BrevoApiTransport('xkeysib-test-api-key');

        $email = (new Email())
            ->from('noreply@motafawweq.me')
            ->to('student@motafawweq.me')
            ->subject('Verify Email')
            ->html('<p>Your OTP is 123456</p>')
            ->text('Your OTP is 123456');

        $transport->send($email);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test-api-key')
                && $request['subject'] === 'Verify Email'
                && $request['to'][0]['email'] === 'student@motafawweq.me';
        });
    }
}

