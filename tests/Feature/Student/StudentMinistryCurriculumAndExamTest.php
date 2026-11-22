<?php

namespace Tests\Feature\Student;

use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentMinistryCurriculumAndExamTest extends TestCase
{
    use RefreshDatabase;

    protected function createStudent(string $gradeAr = 'الصف الثالث الثانوي', string $trackAr = 'علمي علوم'): User
    {
        $student = User::factory()->create([
            'name' => 'يوسف محمد',
            'email' => 'ministry.student.'.random_int(1000, 9999).'@motafawweq.me',
            'phone' => '010'.random_int(10000000, 99999999),
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
            'student_code' => 'MOE-'.random_int(100000, 999999),
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'stage' => 'stage-thanawya',
            'grade_ar' => $gradeAr,
            'track_ar' => $trackAr,
        ]);

        return $student;
    }

    public function test_student_can_browse_ministry_curricula_by_grade_and_persist_unit_progress(): void
    {
        $student = $this->createStudent('الصف الثالث الثانوي', 'علمي علوم');
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // Default lists student's grade (3rd Secondary) with Biology prioritized for علمي علوم
        $listRes = $this->getJson('/api/v1/student/ministry/curricula')
            ->assertOk()
            ->assertJsonPath('data.studentGradeAr', 'الصف الثالث الثانوي')
            ->assertJsonPath('data.selectedGradeAr', 'الصف الثالث الثانوي');

        $items = $listRes->json('data.items');
        $this->assertGreaterThanOrEqual(5, count($items));
        $this->assertSame('moe-sec3-bio', $items[0]['code']);
        $this->assertSame(0, $items[0]['progressPercent']);
        $this->assertSame('moe-exam-sec3-bio', $items[0]['examCode']);

        // Toggle unit 0 completed -> progress becomes 20% (1/5 units)
        $this->postJson('/api/v1/student/ministry/curricula/moe-sec3-bio/units/0/toggle')
            ->assertOk()
            ->assertJsonPath('data.isCompleted', true)
            ->assertJsonPath('data.completedUnitsCount', 1)
            ->assertJsonPath('data.progressPercent', 20);

        // Toggle unit 1 completed -> progress becomes 40% (2/5 units)
        $this->postJson('/api/v1/student/ministry/curricula/moe-sec3-bio/units/1/toggle')
            ->assertOk()
            ->assertJsonPath('data.completedUnitsCount', 2)
            ->assertJsonPath('data.progressPercent', 40);

        // Reload confirms persistence
        $this->getJson('/api/v1/student/ministry/curricula')
            ->assertOk()
            ->assertJsonPath('data.items.0.progressPercent', 40)
            ->assertJsonPath('data.items.0.completedUnitIndices', [0, 1]);

        // Filtering another grade (e.g. 3rd Preparatory) works cleanly
        $prepRes = $this->getJson('/api/v1/student/ministry/curricula?grade='.urlencode('الصف الثالث الإعدادي'))
            ->assertOk()
            ->assertJsonPath('data.selectedGradeAr', 'الصف الثالث الإعدادي');

        $this->assertNotEmpty($prepRes->json('data.items'));
        $this->assertSame('الصف الثالث الإعدادي', $prepRes->json('data.items.0.gradeAr'));

        // Out-of-range unit index returns 422
        $this->postJson('/api/v1/student/ministry/curricula/moe-sec3-bio/units/99/toggle')
            ->assertStatus(422);
    }

    public function test_student_can_take_ministry_guided_exam_without_answer_leakage_and_get_graded_review_and_idempotent_xp(): void
    {
        $student = $this->createStudent('الصف الثالث الثانوي', 'علمي علوم');
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        // 1. List Ministry exams for student's grade
        $catalogRes = $this->getJson('/api/v1/student/ministry/exams')
            ->assertOk()
            ->assertJsonPath('data.selectedGradeAr', 'الصف الثالث الثانوي');

        $examItems = $catalogRes->json('data.items');
        $this->assertGreaterThanOrEqual(5, count($examItems));
        $this->assertTrue($examItems[0]['isMinistry']);

        // 2. Start Biology Ministry exam -> sanitized questions (no correct answers leaked)
        $startRes = $this->postJson('/api/v1/student/ministry/exams/moe-exam-sec3-bio/start')
            ->assertOk()
            ->assertJsonPath('data.examId', 'moe-exam-sec3-bio')
            ->assertJsonPath('data.isMinistry', true)
            ->assertJsonCount(6, 'data.questions');

        $firstQ = $startRes->json('data.questions.0');
        $this->assertArrayNotHasKey('correctIndex', $firstQ);
        $this->assertArrayNotHasKey('correctBool', $firstQ);
        $this->assertArrayNotHasKey('explanationAr', $firstQ);

        // 3. Submit all 6 answers correctly -> full mark (12/12 = 100%, +25 XP)
        $submitRes = $this->postJson('/api/v1/student/ministry/exams/moe-exam-sec3-bio/submit', [
            'tabSwitchesCount' => 0,
            'answers' => [
                ['questionUuid' => 'bio3-q1', 'selectedIndex' => 1],
                ['questionUuid' => 'bio3-q2', 'selectedIndex' => 1],
                ['questionUuid' => 'bio3-q3', 'selectedIndex' => 2],
                ['questionUuid' => 'bio3-q4', 'selectedBool' => true],
                ['questionUuid' => 'bio3-q5', 'selectedIndex' => 1],
                ['questionUuid' => 'bio3-q6', 'selectedIndex' => 2],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 12)
            ->assertJsonPath('data.fullMark', 12)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.isPassed', true)
            ->assertJsonPath('data.isFullMark', true)
            ->assertJsonPath('data.correctCount', 6)
            ->assertJsonPath('data.wrongCount', 0)
            ->assertJsonPath('data.xpAwarded', 25)
            ->assertJsonCount(6, 'data.review');

        $this->assertNotEmpty($submitRes->json('data.review.0.explanationAr'));

        // 4. Fetching result endpoint returns full graded review
        $this->getJson('/api/v1/student/ministry/exams/moe-exam-sec3-bio/result')
            ->assertOk()
            ->assertJsonPath('data.score', 12)
            ->assertJsonCount(6, 'data.review');

        // 5. Retaking the same Ministry exam works cleanly WITHOUT double-awarding XP
        $this->postJson('/api/v1/student/ministry/exams/moe-exam-sec3-bio/start', ['retake' => true])
            ->assertOk();

        $this->postJson('/api/v1/student/ministry/exams/moe-exam-sec3-bio/submit', [
            'answers' => [
                ['questionUuid' => 'bio3-q1', 'selectedIndex' => 0], // wrong
            ],
        ])->assertCreated()
            ->assertJsonPath('data.xpAwarded', 0);

        $this->assertSame(
            1,
            StudentXpLedger::where('student_id', $student->id)
                ->where('source_type', 'ministry_exam_completed')
                ->count()
        );
    }

    public function test_non_student_cannot_access_student_ministry_endpoints(): void
    {
        $teacher = User::factory()->create([
            'role' => UserRole::TEACHER,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($teacher, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/student/ministry/curricula')->assertForbidden();
        $this->getJson('/api/v1/student/ministry/exams')->assertForbidden();
    }
}
