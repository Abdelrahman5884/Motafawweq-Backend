<?php

namespace Tests\Feature\Student;

use App\Enums\BillingPeriod;
use App\Enums\StudentPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\StudentSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSubscriptionGatingTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(string $email = 'gated.student@motafawweq.me'): User
    {
        return User::factory()->create([
            'name' => 'أحمد علاء',
            'email' => $email,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function activatePlan(User $student, StudentPlanTier $tier, int $daysFromNow = 30): StudentSubscription
    {
        StudentSubscription::where('student_id', $student->id)
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        return StudentSubscription::create([
            'student_id' => $student->id,
            'plan_tier' => $tier,
            'plan_name_ar' => $tier->labelAr(),
            'plan_name_en' => $tier->labelEn(),
            'billing_period' => BillingPeriod::MONTHLY,
            'amount_egp' => $tier === StudentPlanTier::PRO ? 80 : ($tier === StudentPlanTier::PLUS ? 50 : 0),
            'starts_at' => now()->subDay(),
            'renews_at' => $tier === StudentPlanTier::FREE ? null : now()->addDays($daysFromNow),
            'status' => 'active',
        ]);
    }

    public function test_free_student_can_access_free_features_but_is_blocked_from_plus_and_pro_features(): void
    {
        $student = $this->createStudent();
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Auth /me exposes free plan tier
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.plan_tier', 'free')
            ->assertJsonPath('data.unlocked_features.leagues', false)
            ->assertJsonPath('data.unlocked_features.smart_lecture', false);

        // Free endpoints remain accessible
        $this->getJson('/api/v1/student/dashboard')->assertOk();
        $this->getJson('/api/v1/student/profile')->assertOk();
        $this->getJson('/api/v1/student/groups')->assertOk();
        $this->getJson('/api/v1/student/schedule')->assertOk();
        $this->getJson('/api/v1/student/courses')->assertOk();
        $this->getJson('/api/v1/student/exams')->assertOk();
        $this->getJson('/api/v1/student/homeworks')->assertOk();
        $this->getJson('/api/v1/student/billing')->assertOk();

        // Plus-gated endpoints return 403 SUBSCRIPTION_REQUIRED naming Student Plus
        foreach ([
            '/api/v1/student/leagues',
            '/api/v1/student/leagues/general/leaderboard',
            '/api/v1/student/leagues/history',
            '/api/v1/student/gamification',
            '/api/v1/student/gamification/workspace',
            '/api/v1/student/gamification/heatmap',
            '/api/v1/student/gamification/challenges',
            '/api/v1/student/certificates',
            '/api/v1/student/certificates/workspace',
            '/api/v1/student/certificates/eligibility',
            '/api/v1/student/analytics',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
                ->assertJsonPath('current_plan', 'free')
                ->assertJsonPath('required_plan', 'plus')
                ->assertJsonPath('required_plan_name_ar', StudentPlanTier::PLUS->labelAr());
        }

        // Pro-gated AI endpoints return 403 SUBSCRIPTION_REQUIRED naming Student Pro
        foreach ([
            '/api/v1/student/converted-lectures',
            '/api/v1/student/revision',
            '/api/v1/student/mistake-bank',
        ] as $endpoint) {
            $this->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
                ->assertJsonPath('current_plan', 'free')
                ->assertJsonPath('required_plan', 'pro')
                ->assertJsonPath('required_plan_name_ar', StudentPlanTier::PRO->labelAr());
        }
    }

    public function test_plus_student_unlocks_plus_features_but_remains_blocked_from_pro_ai_features(): void
    {
        $student = $this->createStudent('plus.student@motafawweq.me');
        $this->activatePlan($student, StudentPlanTier::PLUS);
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.plan_tier', 'plus')
            ->assertJsonPath('data.unlocked_features.leagues', true)
            ->assertJsonPath('data.unlocked_features.gamification', true)
            ->assertJsonPath('data.unlocked_features.certificates', true)
            ->assertJsonPath('data.unlocked_features.smart_lecture', false)
            ->assertJsonPath('data.unlocked_features.converted_lectures', false);

        // Plus features succeed
        $this->getJson('/api/v1/student/leagues')->assertOk();
        $this->getJson('/api/v1/student/gamification/workspace')->assertOk();
        $this->getJson('/api/v1/student/certificates/workspace')->assertOk();
        $this->getJson('/api/v1/student/analytics')->assertOk();

        // Pro AI features stay locked
        $this->getJson('/api/v1/student/converted-lectures')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'plus')
            ->assertJsonPath('required_plan', 'pro');

        $this->getJson('/api/v1/student/revision')
            ->assertForbidden()
            ->assertJsonPath('required_plan', 'pro');

        $this->getJson('/api/v1/student/mistake-bank')
            ->assertForbidden()
            ->assertJsonPath('required_plan', 'pro');
    }

    public function test_pro_student_unlocks_all_features_and_expired_plan_relocks_immediately(): void
    {
        $student = $this->createStudent('pro.student@motafawweq.me');
        $sub = $this->activatePlan($student, StudentPlanTier::PRO, 30);
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // All Plus + Pro endpoints succeed
        $this->getJson('/api/v1/student/leagues')->assertOk();
        $this->getJson('/api/v1/student/gamification/workspace')->assertOk();
        $this->getJson('/api/v1/student/certificates/workspace')->assertOk();
        $this->getJson('/api/v1/student/converted-lectures')->assertOk();
        $this->getJson('/api/v1/student/revision')->assertOk();
        $this->getJson('/api/v1/student/mistake-bank')->assertOk();

        // Expire the Pro subscription -> must auto-downgrade to Free & block immediately
        $sub->update(['renews_at' => now()->subMinute()]);

        $this->getJson('/api/v1/student/converted-lectures')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'pro');

        $this->getJson('/api/v1/student/leagues')
            ->assertForbidden()
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('required_plan', 'plus');

        $this->assertSame('expired', $sub->fresh()->status);
    }
}
