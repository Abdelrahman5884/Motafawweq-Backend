<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PlatformChampion;
use App\Models\RepublicLeague;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRepublicLeagueAndNomineesTest extends TestCase
{
    use RefreshDatabase;

    private function createVerifiedAdmin(): User
    {
        return User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function createStudentWithGradeAndXp(string $name, string $gradeAr, int $xp, string $school = 'مدرسة المتفوقين'): User
    {
        $student = User::factory()->create([
            'name' => $name,
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        StudentProfile::create([
            'student_id' => $student->id,
            'grade_ar' => $gradeAr,
            'school_name' => $school,
            'governorate' => 'القاهرة',
            'current_streak_days' => 5,
            'show_on_leaderboard' => true,
        ]);

        if ($xp > 0) {
            StudentXpLedger::create([
                'student_id' => $student->id,
                'source_type' => 'teacher_league_bonus',
                'source_id' => $student->id,
                'points' => $xp,
                'reason_ar' => 'نقاط تميز دوري الجمهورية',
            ]);
        }

        return $student;
    }

    public function test_non_admin_cannot_access_republic_league_and_nominee_endpoints(): void
    {
        $student = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($student, ['access']);

        $this->getJson('/api/v1/admin/leagues/republic/leaderboard')->assertStatus(403);
        $this->getJson('/api/v1/admin/leagues/republic/nominees')->assertStatus(403);
        $this->postJson('/api/v1/admin/champions/approve-nominee', [])->assertStatus(403);
        $this->postJson('/api/v1/admin/champions/upload-photo', [])->assertStatus(403);
    }

    public function test_overview_lists_all_six_canonical_grades_with_accurate_counts(): void
    {
        $admin = $this->createVerifiedAdmin();

        foreach (RepublicLeague::SUPPORTED_GRADES as $idx => $g) {
            $this->createStudentWithGradeAndXp("طالب {$idx}", $g['ar'], ($idx + 1) * 100);
        }

        Sanctum::actingAs($admin, ['access']);

        $res = $this->getJson('/api/v1/admin/leagues/republic')
            ->assertOk()
            ->assertJsonPath('success', true);

        $grades = $res->json('data.grades');
        $this->assertCount(6, $grades);
        $this->assertSame('الصف الأول الإعدادي', $grades[0]['value']);
        $this->assertSame('الصف الثالث الثانوي', $grades[5]['value']);

        foreach ($grades as $gradeRow) {
            $this->assertSame(1, $gradeRow['studentsCount']);
        }
    }

    public function test_admin_can_launch_grade_scoped_league_and_paginate_searchable_leaderboard(): void
    {
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        // Launch platform-wide Republic League first
        $startRes = $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'دوري الجمهورية التجريبي',
            'duration' => 'monthly',
        ], ['Idempotency-Key' => 'rep-start-test-1'])
            ->assertStatus(201);

        $leagueId = $startRes->json('data.id');
        $this->assertNotEmpty($leagueId);

        // Create 8 students in 3rd Secondary + 2 in 1st Preparatory
        for ($i = 1; $i <= 8; $i++) {
            $this->createStudentWithGradeAndXp(
                "متفوق ثانوي {$i}",
                'الصف الثالث الثانوي',
                $i * 50,
                "مدرسة رقم {$i}"
            );
        }
        $this->createStudentWithGradeAndXp('بطل أولى إعدادي', 'الصف الأول الإعدادي', 999);

        // Page 1 (per_page = 5) for 3rd Secondary only
        $page1 = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&page=1&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 8)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.current_page', 1);

        $this->assertCount(5, $page1->json('data'));
        $this->assertSame(1, $page1->json('data.0.rank'));
        $this->assertSame('متفوق ثانوي 8', $page1->json('data.0.name'));

        // Page 2
        $page2 = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&page=2&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 2);

        $this->assertCount(3, $page2->json('data'));
        $this->assertSame(6, $page2->json('data.0.rank'));

        // Search preserves true rank inside the grade
        $searchRes = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&search='.urlencode('متفوق ثانوي 3'))
            ->assertOk();

        $this->assertCount(1, $searchRes->json('data'));
        $this->assertSame(6, $searchRes->json('data.0.rank'));
    }

    public function test_auto_nominees_approval_upsert_and_public_slider_sync(): void
    {
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'موسم نوفمبر 2026',
            'duration' => 'monthly',
        ], ['Idempotency-Key' => 'rep-start-nom-1'])->assertStatus(201);

        $topStudent = $this->createStudentWithGradeAndXp('يوسف محمود الأول', 'الصف الثاني الثانوي', 850, 'مدرسة النصر');
        $this->createStudentWithGradeAndXp('علي حسن الثاني', 'الصف الثاني الثانوي', 420, 'مدرسة الأمل');

        // 1. Auto-detected nominees should include top student with pending status
        $nomineesRes = $this->getJson('/api/v1/admin/leagues/republic/nominees?grade='.urlencode('الصف الثاني الثانوي'))
            ->assertOk();

        $firstNominee = $nomineesRes->json('data.0');
        $this->assertSame($topStudent->id, $firstNominee['studentId']);
        $this->assertSame('pending', $firstNominee['approvalStatus']);

        // 2. Admin customizes data & approves for homepage slider
        $suggested = $firstNominee['suggestedChampion'];
        $approvePayload = array_merge($suggested, [
            'nameAr' => 'يوسف محمود المتفوق',
            'schoolAr' => 'مدرسة النصر الثانوية — القاهرة',
            'image' => '/characters/real-male-champ-2.png',
            'isPinned' => true,
        ]);

        $approveRes = $this->postJson('/api/v1/admin/champions/approve-nominee', $approvePayload, [
            'Idempotency-Key' => 'approve-nominee-1',
        ])->assertOk();

        $champUuid = $approveRes->json('data.id');
        $this->assertSame('يوسف محمود المتفوق', $approveRes->json('data.nameAr'));
        $this->assertSame('الصف الثاني الثانوي', $approveRes->json('data.gradeAr'));
        $this->assertSame('republic_league', $approveRes->json('data.source'));

        // 3. Re-approving same student in same league updates instead of creating a duplicate
        $this->postJson('/api/v1/admin/champions/approve-nominee', array_merge($approvePayload, [
            'scoreAr' => '900 نقطة',
        ]), ['Idempotency-Key' => 'approve-nominee-2'])
            ->assertOk()
            ->assertJsonPath('data.id', $champUuid)
            ->assertJsonPath('data.scoreAr', '900 نقطة');

        $this->assertSame(1, PlatformChampion::query()->where('student_id', $topStudent->id)->count());

        // 4. Nominee status now reflects approved_pinned
        $this->getJson('/api/v1/admin/leagues/republic/nominees?grade='.urlencode('الصف الثاني الثانوي'))
            ->assertOk()
            ->assertJsonPath('data.0.approvalStatus', 'approved_pinned');

        // 5. Public homepage slider immediately serves the approved champion
        $publicRes = $this->getJson('/api/v1/public/champions')->assertOk();
        $this->assertSame($champUuid, $publicRes->json('data.0.id'));
        $this->assertSame('الصف الثاني الثانوي', $publicRes->json('data.0.gradeAr'));

        // 6. Paginated admin champions list filters cleanly by grade
        $this->getJson('/api/v1/admin/champions?grade='.urlencode('الصف الثاني الثانوي').'&page=1&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $champUuid);
    }

    public function test_champion_photo_upload_accepts_valid_image_and_rejects_spoofed_file(): void
    {
        Storage::fake('public');
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        // Valid spec-compliant 120x120 PNG generated in pure PHP (works even without GD extension)
        $validImage = UploadedFile::fake()->createWithContent('winner.png', $this->makeValidPngBytes(120, 120));
        $uploadRes = $this->postJson('/api/v1/admin/champions/upload-photo', [
            'photo' => $validImage,
        ])->assertStatus(201);

        $url = (string) $uploadRes->json('data.url');
        $path = (string) $uploadRes->json('data.path');
        $this->assertStringStartsWith('/storage/champions/', $url);
        Storage::disk('public')->assertExists($path);

        // Spoofed PHP text file disguised as .jpg must be rejected
        $spoofed = UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]); ?>');
        $this->postJson('/api/v1/admin/champions/upload-photo', [
            'photo' => $spoofed,
        ])->assertStatus(422);
    }

    /**
     * Generate a genuine spec-compliant RGB PNG binary without requiring the GD extension.
     */
    private function makeValidPngBytes(int $width, int $height): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $scanline = "\x00".str_repeat("\x15\x88\xC7", $width);
        $raw = str_repeat($scanline, $height);
        $idat = gzcompress($raw, 6);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', $ihdr)
            .$chunk('IDAT', $idat !== false ? $idat : '')
            .$chunk('IEND', '');
    }
}
