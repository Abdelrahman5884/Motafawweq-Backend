<?php

namespace Tests\Feature\Teacher;

use App\Enums\StageType;
use App\Enums\TeacherPlanTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\User;
use App\Traits\BelongsToTeacher;
use App\Traits\HasSecurityAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DummyTeacherOwnedModel extends Model
{
    use BelongsToTeacher;

    protected $table = 'dummy_teacher_models';
    protected $guarded = [];
}

class TeacherSecurityFoundationTest extends TestCase
{
    use RefreshDatabase, HasSecurityAudit;

    public function test_security_audit_log_is_persisted_and_immutable(): void
    {
        $teacher = User::factory()->create([
            'role'   => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->actingAs($teacher, 'sanctum');

        $this->logSecurityEvent('teacher.test_audit_event', [
            'action' => 'sensitive_operation',
        ], 'warning');

        $log = SecurityAuditLog::where('event', 'teacher.test_audit_event')->first();

        $this->assertNotNull($log);
        $this->assertEquals($teacher->id, $log->user_id);
        $this->assertEquals('warning', $log->level);
        $this->assertEquals('sensitive_operation', $log->context['action']);
        $this->assertNotEmpty($log->uuid);

        // Attempting to tamper with an existing audit log must throw LogicException
        $this->expectException(LogicException::class);
        $log->update(['level' => 'info']);
    }

    public function test_security_audit_log_cannot_be_deleted(): void
    {
        $log = SecurityAuditLog::create([
            'event' => 'teacher.immutable_delete_check',
            'level' => 'critical',
        ]);

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_belongs_to_teacher_prevents_idor_and_logs_violation(): void
    {
        $teacherA = User::factory()->create([
            'role'   => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
        ]);

        $teacherB = User::factory()->create([
            'role'   => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
        ]);

        $resourceOwnedByA = new DummyTeacherOwnedModel([
            'uuid'       => '11111111-2222-3333-4444-555555555555',
            'teacher_id' => $teacherA->id,
        ]);

        // Owner can access
        $this->assertTrue($resourceOwnedByA->isOwnedBy($teacherA));
        $resourceOwnedByA->assertOwnedBy($teacherA);

        // Another teacher attempting access triggers AuthorizationException + SecurityAuditLog
        $this->assertFalse($resourceOwnedByA->isOwnedBy($teacherB));

        try {
            $resourceOwnedByA->assertOwnedBy($teacherB);
            $this->fail('Expected AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            $audit = SecurityAuditLog::where('event', 'teacher.idor_attempt_blocked')->first();
            $this->assertNotNull($audit);
            $this->assertEquals('warning', $audit->level);
            $this->assertEquals($teacherB->id, $audit->context['attempted_by_user_id']);
            $this->assertEquals($teacherA->id, $audit->context['resource_owner_id']);
        }
    }

    public function test_teacher_plan_tiers_and_stage_catalog_rules(): void
    {
        $this->assertEquals(1, TeacherPlanTier::FREE->maxCourses());
        $this->assertEquals(10, TeacherPlanTier::PLUS->maxCourses());
        $this->assertNull(TeacherPlanTier::PRO->maxCourses());

        $this->assertFalse(TeacherPlanTier::FREE->allowsCertificates());
        $this->assertTrue(TeacherPlanTier::PLUS->allowsCertificates());
        $this->assertTrue(TeacherPlanTier::PRO->allowsWhatsappParentAlerts());

        $this->assertCount(4, StageType::catalog());
        $this->assertContains('الصف الثالث الثانوي', StageType::allowedGradesAr());
    }
}

