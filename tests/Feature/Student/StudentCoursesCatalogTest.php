<?php

namespace Tests\Feature\Student;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\LessonStatus;
use App\Enums\PayoutAccountType;
use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\DiscountCoupon;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudyGroup;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCoursesCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function createTeacher(string $name = 'د. حسام الكيمياء'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'course.teacher.'.random_int(1000, 9999).'@motafawweq.com',
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    protected function createStudent(string $name = 'آدم خالد'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => 'course.student.'.random_int(1000, 9999).'@motafawweq.com',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'STD-'.random_int(100000, 999999),
        ]);
    }

    public function test_courses_catalog_returns_accurate_progress_next_lesson_payout_accounts_and_ordering(): void
    {
        $teacherA = $this->createTeacher('د. رانيا الفيزياء');
        $teacherB = $this->createTeacher('أ. طارق الرياضيات');
        $student = $this->createStudent();

        // Enrolled course with 2 published lessons + 1 draft lesson
        $enrolledCourse = Course::create([
            'teacher_id' => $teacherA->id,
            'title_ar' => 'الفيزياء الكهربية الشاملة',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 350,
            'status' => CourseStatus::PUBLISHED,
            'lessons_count' => 5, // intentionally stale to verify live published count wins
        ]);

        $lesson1 = Lesson::create([
            'course_id' => $enrolledCourse->id,
            'teacher_id' => $teacherA->id,
            'title_ar' => 'التيار الكهربي',
            'duration_seconds' => 1800,
            'status' => LessonStatus::PUBLISHED,
        ]);
        $lesson2 = Lesson::create([
            'course_id' => $enrolledCourse->id,
            'teacher_id' => $teacherA->id,
            'title_ar' => 'قانون كيرشوف',
            'duration_seconds' => 2400,
            'status' => LessonStatus::PUBLISHED,
        ]);
        Lesson::create([
            'course_id' => $enrolledCourse->id,
            'teacher_id' => $teacherA->id,
            'title_ar' => 'مسودة غير منشورة',
            'duration_seconds' => 900,
            'status' => LessonStatus::DRAFT,
        ]);

        // Enroll student in teacherA's course via active StudyGroup
        $group = StudyGroup::create([
            'teacher_id' => $teacherA->id,
            'course_id' => $enrolledCourse->id,
            'name_ar' => 'مجموعة الفيزياء',
            'subject_ar' => 'الفيزياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر الرواد',
            'hall_name' => 'قاعة 1',
            'price_egp' => 350,
            'join_code' => 'PHY-'.random_int(1000, 9999),
            'status' => 'active',
        ]);
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacherA->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);

        // Mark lesson 1 completed -> nextLessonId MUST point to lesson 2!
        StudentLessonProgress::create([
            'student_id' => $student->id,
            'lesson_id' => $lesson1->id,
            'course_id' => $enrolledCourse->id,
            'watched_seconds' => 1800,
            'duration_seconds' => 1800,
            'progress_percent' => 100,
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        // Available paid course from teacherB (plus 1 draft course that MUST be hidden)
        $availableCourse = Course::create([
            'teacher_id' => $teacherB->id,
            'title_ar' => 'التفاضل والتكامل للثانوية العامة',
            'subject_ar' => 'الرياضيات',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 500,
            'status' => CourseStatus::PUBLISHED,
        ]);
        Course::create([
            'teacher_id' => $teacherB->id,
            'title_ar' => 'كورس مسودة مخفي',
            'subject_ar' => 'الرياضيات',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 200,
            'status' => CourseStatus::DRAFT,
        ]);

        // Active & inactive payout accounts for teacherB
        $activeAcc = TeacherPayoutAccount::create([
            'teacher_id' => $teacherB->id,
            'type' => PayoutAccountType::VODAFONE,
            'label_ar' => 'فودافون كاش',
            'label_en' => 'Vodafone Cash',
            'account_identifier' => '01099887766',
            'is_default' => true,
            'is_active' => true,
        ]);
        TeacherPayoutAccount::create([
            'teacher_id' => $teacherB->id,
            'type' => PayoutAccountType::INSTAPAY,
            'label_ar' => 'حساب موقوف',
            'label_en' => 'Disabled InstaPay',
            'account_identifier' => 'disabled@instapay',
            'is_default' => false,
            'is_active' => false,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $res = $this->getJson('/api/v1/student/courses')->assertOk();

        // Only 2 published courses (draft excluded), enrolled sorted first
        $res->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $enrolledCourse->uuid)
            ->assertJsonPath('data.0.isEnrolled', true)
            ->assertJsonPath('data.0.lessonsCount', 2)
            ->assertJsonPath('data.0.completedLessons', 1)
            ->assertJsonPath('data.0.progressPercent', 50)
            ->assertJsonPath('data.0.publishedDurationSeconds', 4200)
            ->assertJsonPath('data.0.nextLessonId', $lesson2->uuid)
            ->assertJsonPath('data.1.id', $availableCourse->uuid)
            ->assertJsonPath('data.1.isEnrolled', false)
            ->assertJsonPath('data.1.nextLessonId', null)
            ->assertJsonCount(1, 'data.1.payoutAccounts')
            ->assertJsonPath('data.1.payoutAccounts.0.id', $activeAcc->uuid)
            ->assertJsonPath('data.1.payoutAccounts.0.accountIdentifier', '01099887766');
    }

    public function test_course_enrollment_blocks_draft_and_duplicate_enrolled_and_supports_coupon_checkout(): void
    {
        $teacher = $this->createTeacher();
        $student = $this->createStudent();

        $draftCourse = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'كورس مسودة',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 300,
            'status' => CourseStatus::DRAFT,
        ]);

        $paidCourse = Course::create([
            'teacher_id' => $teacher->id,
            'title_ar' => 'الكيمياء العضوية الكاملة',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'price_egp' => 400,
            'status' => CourseStatus::PUBLISHED,
        ]);

        $payoutAcc = TeacherPayoutAccount::create([
            'teacher_id' => $teacher->id,
            'type' => PayoutAccountType::INSTAPAY,
            'label_ar' => 'إنستاباي المعلم',
            'label_en' => 'Teacher InstaPay',
            'account_identifier' => 'dr.hossam@instapay',
            'is_default' => true,
            'is_active' => true,
        ]);

        DiscountCoupon::create([
            'code' => 'CHEM25',
            'discount_percent' => 25,
            'min_order_egp' => 100,
            'is_active' => true,
            'max_uses' => 10,
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. Draft course rejected with 422
        $this->postJson('/api/v1/student/billing/payments', [
            'course_uuid' => $draftCourse->uuid,
            'payment_method' => 'instapay',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['course_uuid']);

        // 2. Validate coupon returns accurate 25% discount (400 -> 300 EGP)
        $this->postJson('/api/v1/student/billing/coupons/validate', [
            'code' => 'chem25',
            'amount_egp' => 400,
        ])->assertOk()
            ->assertJsonPath('data.discountEgp', 100)
            ->assertJsonPath('data.finalAmountEgp', 300);

        // 3. Submit enrollment payment with coupon & payout account
        $this->postJson('/api/v1/student/billing/payments', [
            'course_uuid' => $paidCourse->uuid,
            'payout_account_uuid' => $payoutAcc->uuid,
            'payment_method' => 'instapay',
            'coupon_code' => 'CHEM25',
            'notes' => 'تم التحويل من رقم 01012345678',
        ])->assertCreated()
            ->assertJsonPath('data.amountEgp', 300)
            ->assertJsonPath('data.status', 'pending');

        // 4. Course catalog now reports isPending = true
        $this->getJson('/api/v1/student/courses')
            ->assertOk()
            ->assertJsonPath('data.0.id', $paidCourse->uuid)
            ->assertJsonPath('data.0.isPending', true);

        // 5. Once enrolled, attempting to pay again for the same course_uuid is blocked with 422
        $group = StudyGroup::create([
            'teacher_id' => $teacher->id,
            'course_id' => $paidCourse->id,
            'name_ar' => 'مجموعة العضوية',
            'subject_ar' => 'الكيمياء',
            'grade_ar' => 'الصف الثالث الثانوي',
            'center_name' => 'سنتر النخبة',
            'hall_name' => 'قاعة 2',
            'price_egp' => 400,
            'join_code' => 'CHM-'.random_int(1000, 9999),
            'status' => 'active',
        ]);
        GroupEnrollment::create([
            'study_group_id' => $group->id,
            'teacher_id' => $teacher->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::ENROLLED,
            'enrolled_at' => now(),
        ]);

        $this->postJson('/api/v1/student/billing/payments', [
            'course_uuid' => $paidCourse->uuid,
            'payment_method' => 'instapay',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['course_uuid']);
    }
}
