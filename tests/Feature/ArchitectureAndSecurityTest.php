<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArchitectureAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * Test security headers are present on API responses.
     */
    public function test_api_responses_include_strict_security_headers(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('X-Powered-By');
    }

    /**
     * Test input sanitization strips dangerous HTML tags.
     */
    public function test_input_sanitization_removes_harmful_tags(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => '<b>أحمد</b> <script></script>علي',
            'email'                 => 'ahmed@motafawweq.test',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'ahmed@motafawweq.test',
            'name'  => 'أحمد علي', // tags stripped
        ]);
    }

    /**
     * Test registration validation errors return uniform 422 JSON format.
     */
    public function test_registration_validation_fails_with_standard_json(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'  => '',
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
                 ->assertJson(['success' => false])
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'errors' => ['name', 'email', 'password'],
                 ]);
    }

    /**
     * Test successful login issues an access/refresh token pair.
     */
    public function test_user_can_login_and_receive_token(): void
    {
        User::factory()->create([
            'email'    => 'student@motafawweq.test',
            'password' => 'StrongPass123!',
            'role'     => UserRole::STUDENT,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'student@motafawweq.test',
            'password' => 'StrongPass123!',
        ]);

        $response->assertStatus(200)
                 ->assertJson(['success' => true])
                 ->assertJsonStructure([
                     'data' => [
                         'user'   => ['id', 'name', 'email', 'role', 'role_label'],
                         'tokens' => ['access_token', 'refresh_token'],
                     ],
                 ]);
    }

    /**
     * Test unauthenticated access returns 401 JSON.
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
             ->assertStatus(401)
             ->assertJson(['success' => false]);
    }

    /**
     * Test student is forbidden from admin endpoints (Role check).
     */
    public function test_student_cannot_access_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::STUDENT]), ['access']);

        $this->getJson('/api/v1/admin/users')
             ->assertStatus(403)
             ->assertJson(['success' => false]);
    }

    /**
     * Test admin can access admin endpoints.
     */
    public function test_admin_can_access_admin_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['access']);

        $this->getJson('/api/v1/admin/users')
             ->assertStatus(200)
             ->assertJson(['success' => true]);
    }

    /**
     * Test user logout revokes access token.
     */
    public function test_user_can_logout_and_revoke_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['access'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
             ->postJson('/api/v1/auth/logout')
             ->assertStatus(200)
             ->assertJson(['success' => true]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Test changing password verifies current password and succeeds.
     */
    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123!']);
        Sanctum::actingAs($user, ['access']);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password'          => 'OldPassword123!',
            'new_password'              => 'NewStrongPass123!',
            'new_password_confirmation' => 'NewStrongPass123!',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('NewStrongPass123!', $user->fresh()->password));
    }
}
