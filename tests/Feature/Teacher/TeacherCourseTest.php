<?php

namespace Tests\Feature\Teacher;

use App\Enums\TeacherPlanTier;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherCourseTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedTeacher(
        string $email = 'courses.teacher@motafawweq.com',
        TeacherPlanTier $planTier = TeacherPlanTier::PLUS
    ): array {
        $teacher = User::factory()->create([
            'name'              => 'د. سلمى السيد',
            'email'             => $email,
            'phone'             => '010' . random_int(10000000, 99999999),
            'role'              => UserRole::TEACHER,
            'status'            => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        $profile = TeacherProfile::create([
            'user_id'    => $teacher->id,
            'name_ar'    => $teacher->name,
            'subject_ar' => 'الأحياء',
        ]);
        $profile->forceFill(['plan_tier' => $planTier])->save();

        $token = $teacher->createToken('test-device', [TokenType::ACCESS->value])->plainTextToken;

        return [$teacher, $token, $profile];
    }

    public function test_teacher_can_create_list_search_update_and_soft_delete_courses(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher();

        // 1. Create Course 1 (Secondary)
        $createRes = $this->withToken($token)
            ->postJson('/api/v1/teacher/courses', [
                'title'       => 'ماستر كلاس الأحياء: البناء الضوئي والوراثة',
                'stage'       => 'sec',
                'grade'       => 'الصف الثالث الثانوي',
                'price'       => 350,
                'description' => 'كورس شامل يغطي الدعامة والحركة والبيولوجيا الجزيئية.',
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titleAr', 'ماستر كلاس الأحياء: البناء الضوئي والوراثة')
            ->assertJsonPath('data.stageId', 'sec')
            ->assertJsonPath('data.stage', 'المرحلة الثانوية')
            ->assertJsonPath('data.gradeAr', 'الصف الثالث الثانوي')
            ->assertJsonPath('data.priceEgp', 350);

        $courseUuid = $createRes->json('data.id');

        // 2. Create Course 2 (Preparatory)
        $this->withToken($token)
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'العلوم المتكاملة وقوانين مندل',
                'stage' => 'prep',
                'grade' => 'الصف الثالث الإعدادي',
                'price' => 180,
            ])
            ->assertCreated();

        // 3. List all courses
        $this->withToken($token)
            ->getJson('/api/v1/teacher/courses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        // 4. Search courses by query
        $this->withToken($token)
            ->getJson('/api/v1/teacher/courses?search=مندل')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gradeAr', 'الصف الثالث الإعدادي');

        // 5. Update Course 1
        $updateRes = $this->withToken($token)
            ->putJson("/api/v1/teacher/courses/{$courseUuid}", [
                'title'        => 'ماستر كلاس الأحياء المطور 2026',
                'price'        => 400,
                'hours'        => '32 ساعة',
                'lessonsCount' => 16,
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.titleAr', 'ماستر كلاس الأحياء المطور 2026')
            ->assertJsonPath('data.priceEgp', 400)
            ->assertJsonPath('data.hoursTotal', '32 ساعة')
            ->assertJsonPath('data.lessonsCount', 16);

        // 6. Soft-delete Course 1
        $this->withToken($token)
            ->deleteJson("/api/v1/teacher/courses/{$courseUuid}")
            ->assertOk();

        $this->assertSoftDeleted('courses', ['uuid' => $courseUuid]);

        // Only Course 2 remains in active list
        $this->withToken($token)
            ->getJson('/api/v1/teacher/courses')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.course.deleted',
        ]);
    }

    public function test_free_plan_teacher_is_blocked_when_exceeding_max_courses_quota(): void
    {
        [$teacher, $token] = $this->createAuthenticatedTeacher('free.teacher@motafawweq.com', TeacherPlanTier::FREE);

        // 1st course succeeds (Free tier limit = 1 course)
        $firstRes = $this->withToken($token)
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'الكورس الأول المجاني',
                'stage' => 'sec',
                'grade' => 'الصف الأول الثانوي',
                'price' => 200,
            ]);

        $firstRes->assertCreated();
        $firstUuid = $firstRes->json('data.id');

        // 2nd course is blocked by CheckTeacherPlanQuota middleware
        $secondRes = $this->withToken($token)
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'الكورس الثاني فوق الحد',
                'stage' => 'sec',
                'grade' => 'الصف الثاني الثانوي',
                'price' => 250,
            ]);

        $secondRes->assertStatus(403)
            ->assertJsonPath('error_code', 'PLAN_QUOTA_EXCEEDED')
            ->assertJsonPath('current_plan', 'free')
            ->assertJsonPath('feature', 'courses');

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacher->id,
            'event'   => 'teacher.plan_quota_exceeded',
        ]);

        // After deleting the 1st course, the teacher can create a new course
        $this->withToken($token)->deleteJson("/api/v1/teacher/courses/{$firstUuid}")->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'الكورس البديل بعد الحذف',
                'stage' => 'sec',
                'grade' => 'الصف الثاني الثانوي',
                'price' => 250,
            ])
            ->assertCreated();
    }

    public function test_cross_teacher_idor_access_to_courses_is_strictly_blocked_and_logged(): void
    {
        [$teacherA] = $this->createAuthenticatedTeacher('teacher.a.course@motafawweq.com');
        [$teacherB, $tokenB] = $this->createAuthenticatedTeacher('teacher.b.course@motafawweq.com');

        $courseA = Course::create([
            'teacher_id'    => $teacherA->id,
            'title_ar'      => 'مقرر خاص بالمعلم أ',
            'stage_id'      => 'sec',
            'stage_name_ar' => 'المرحلة الثانوية',
            'grade_ar'      => 'الصف الثالث الثانوي',
            'subject_ar'    => 'الفيزياء',
            'price_egp'     => 350,
        ]);

        // Teacher B's list does not include Teacher A's course
        $this->withToken($tokenB)
            ->getJson('/api/v1/teacher/courses')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Teacher B cannot view Teacher A's course
        $this->withToken($tokenB)
            ->getJson("/api/v1/teacher/courses/{$courseA->uuid}")
            ->assertStatus(403);

        // Teacher B cannot update Teacher A's course
        $this->withToken($tokenB)
            ->putJson("/api/v1/teacher/courses/{$courseA->uuid}", [
                'title' => 'محاولة اختراق الكورس',
            ])
            ->assertStatus(403);

        // Teacher B cannot delete Teacher A's course
        $this->withToken($tokenB)
            ->deleteJson("/api/v1/teacher/courses/{$courseA->uuid}")
            ->assertStatus(403);

        $this->assertDatabaseHas('courses', [
            'id'         => $courseA->id,
            'title_ar'   => 'مقرر خاص بالمعلم أ',
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $teacherB->id,
            'event'   => 'teacher.idor_attempt_blocked',
            'level'   => 'warning',
        ]);
    }
}

