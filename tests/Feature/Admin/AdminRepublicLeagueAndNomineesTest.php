<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PlatformChampion;
use App\Models\RepublicLeague;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use Carbon\Carbon;
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

    private function createStudentWithGradeAndXp(
        string $name,
        string $gradeAr,
        int $xp,
        string $school = 'مدرسة المتفوقين',
        ?string $phone = null
    ): User {
        $student = User::factory()->create([
            'name' => $name,
            'phone' => $phone ?? '010'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
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
        $this->postJson('/api/v1/admin/leagues/republic/announce-end', [])->assertStatus(403);
        $this->postJson('/api/v1/admin/champions/approve-nominee', [])->assertStatus(403);
        $this->postJson('/api/v1/admin/champions/upload-photo', [])->assertStatus(403);
    }

    public function test_overview_lists_all_six_canonical_grades_and_exact_day_progress(): void
    {
        $admin = $this->createVerifiedAdmin();

        foreach (RepublicLeague::SUPPORTED_GRADES as $idx => $g) {
            $this->createStudentWithGradeAndXp("طالب {$idx}", $g['ar'], ($idx + 1) * 100);
        }

        // Seed a 30-day league started exactly 4 days ago -> today must be Day 5 of 30
        RepublicLeague::create([
            'started_by' => $admin->id,
            'title_ar' => 'دوري الجمهورية التجريبي',
            'duration_type' => 'monthly',
            'duration_days' => 30,
            'is_active' => true,
            'starts_at' => Carbon::now()->subDays(4)->subHours(1),
            'ends_at' => Carbon::now()->addDays(26),
        ]);

        Sanctum::actingAs($admin, ['access']);

        $res = $this->getJson('/api/v1/admin/leagues/republic')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.league.currentDay', 5)
            ->assertJsonPath('data.league.totalDays', 30);

        $this->assertStringContainsString('اليوم 5 من 30', (string) $res->json('data.league.dayProgressLabelAr'));

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

        $startRes = $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'دوري الجمهورية التجريبي',
            'duration' => 'monthly',
        ], ['Idempotency-Key' => 'rep-start-test-1'])
            ->assertStatus(201)
            ->assertJsonPath('data.currentDay', 1)
            ->assertJsonPath('data.totalDays', 30);

        $this->assertNotEmpty($startRes->json('data.id'));

        for ($i = 1; $i <= 8; $i++) {
            $this->createStudentWithGradeAndXp(
                "متفوق ثانوي {$i}",
                'الصف الثالث الثانوي',
                $i * 50,
                "مدرسة رقم {$i}"
            );
        }
        $this->createStudentWithGradeAndXp('بطل أولى إعدادي', 'الصف الأول الإعدادي', 999);

        $page1 = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&page=1&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 8)
            ->assertJsonPath('meta.pagination.last_page', 2)
            ->assertJsonPath('meta.pagination.current_page', 1);

        $this->assertCount(5, $page1->json('data'));
        $this->assertSame(1, $page1->json('data.0.rank'));
        $this->assertSame('متفوق ثانوي 8', $page1->json('data.0.name'));
        $this->assertNotEmpty($page1->json('data.0.email'));
        $this->assertNotEmpty($page1->json('data.0.phone'));

        $page2 = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&page=2&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.pagination.current_page', 2);

        $this->assertCount(3, $page2->json('data'));
        $this->assertSame(6, $page2->json('data.0.rank'));

        $searchRes = $this->getJson('/api/v1/admin/leagues/republic/leaderboard?grade='.urlencode('الصف الثالث الثانوي').'&search='.urlencode('متفوق ثانوي 3'))
            ->assertOk();

        $this->assertCount(1, $searchRes->json('data'));
        $this->assertSame(6, $searchRes->json('data.0.rank'));
    }

    public function test_auto_nominees_strictly_returns_top_two_with_contact_details_and_public_slider_hides_pii(): void
    {
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'موسم نوفمبر 2026',
            'duration' => 'monthly',
        ], ['Idempotency-Key' => 'rep-start-nom-1'])->assertStatus(201);

        $first = $this->createStudentWithGradeAndXp('يوسف محمود الأول', 'الصف الثاني الثانوي', 850, 'مدرسة النصر', '01011112222');
        $second = $this->createStudentWithGradeAndXp('علي حسن الثاني', 'الصف الثاني الثانوي', 620, 'مدرسة الأمل', '01033334444');
        $this->createStudentWithGradeAndXp('كريم خالد الثالث', 'الصف الثاني الثانوي', 500, 'مدرسة النيل', '01055556666');
        $this->createStudentWithGradeAndXp('طارق عمر الرابع', 'الصف الثاني الثانوي', 300, 'مدرسة السلام', '01077778888');

        // 1. Strictly ONLY Top 2 (#1 and #2) are auto-nominated, with email & phone visible to admin
        $nomineesRes = $this->getJson('/api/v1/admin/leagues/republic/nominees?grade='.urlencode('الصف الثاني الثانوي'))
            ->assertOk()
            ->assertJsonPath('meta.topPerGrade', 2);

        $this->assertCount(2, $nomineesRes->json('data'));
        $firstNominee = $nomineesRes->json('data.0');
        $secondNominee = $nomineesRes->json('data.1');

        $this->assertSame($first->id, $firstNominee['studentId']);
        $this->assertSame($first->email, $firstNominee['email']);
        $this->assertSame('01011112222', $firstNominee['phone']);
        $this->assertSame($second->id, $secondNominee['studentId']);
        $this->assertSame('01033334444', $secondNominee['phone']);

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

        // 3. Re-approving same student updates instead of duplicating
        $this->postJson('/api/v1/admin/champions/approve-nominee', array_merge($approvePayload, [
            'scoreAr' => '900 نقطة',
        ]), ['Idempotency-Key' => 'approve-nominee-2'])
            ->assertOk()
            ->assertJsonPath('data.id', $champUuid);

        $this->assertSame(1, PlatformChampion::query()->where('student_id', $first->id)->count());

        // 4. Public homepage slider serves approved champion WITHOUT ever exposing email or phone
        $publicRes = $this->getJson('/api/v1/public/champions')->assertOk();
        $publicChamp = $publicRes->json('data.0');
        $this->assertSame($champUuid, $publicChamp['id']);
        $this->assertArrayNotHasKey('email', $publicChamp);
        $this->assertArrayNotHasKey('phone', $publicChamp);
    }

    public function test_admin_can_announce_early_league_end_and_broadcast_honoring_announcement(): void
    {
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        $startRes = $this->postJson('/api/v1/admin/leagues/republic/start', [
            'title_ar' => 'دوري الجمهورية — موسم التكريم',
            'duration' => 'monthly',
        ], ['Idempotency-Key' => 'rep-start-early-end'])->assertStatus(201);

        $leagueUuid = $startRes->json('data.id');

        $endRes = $this->postJson('/api/v1/admin/leagues/republic/announce-end', [
            'league_id' => $leagueUuid,
            'end_now' => true,
            'publish_announcement' => true,
            'announcement_title_ar' => 'إعلان انتهاء الدوري والتكريم المبكر للأوائل',
            'announcement_content_ar' => 'تم إغلاق الجولة مبكراً والبدء في تكريم المركز الأول والثاني على مستوى الجمهورية.',
            'audience' => 'all',
            'priority' => 'urgent',
            'display_days' => 5,
        ], ['Idempotency-Key' => 'rep-announce-end-1'])
            ->assertOk()
            ->assertJsonPath('data.endedEarly', true)
            ->assertJsonPath('data.league.isActive', false)
            ->assertJsonPath('data.announcement.titleAr', 'إعلان انتهاء الدوري والتكريم المبكر للأوائل');

        $annUuid = $endRes->json('data.announcement.id');
        $this->assertNotEmpty($annUuid);

        // Verify the announcement is live on the public announcements endpoint
        $publicAnn = $this->getJson('/api/v1/public/announcements')->assertOk();
        $this->assertTrue(
            collect($publicAnn->json('data'))->contains(fn (array $a) => ($a['id'] ?? null) === $annUuid)
        );
    }

    public function test_champion_photo_upload_accepts_valid_image_and_rejects_spoofed_file(): void
    {
        Storage::fake('public');
        $admin = $this->createVerifiedAdmin();
        Sanctum::actingAs($admin, ['access']);

        $validImage = UploadedFile::fake()->createWithContent('winner.png', $this->makeValidPngBytes(120, 120));
        $uploadRes = $this->postJson('/api/v1/admin/champions/upload-photo', [
            'photo' => $validImage,
        ])->assertStatus(201);

        $url = (string) $uploadRes->json('data.url');
        $path = (string) $uploadRes->json('data.path');
        $this->assertStringStartsWith('/storage/champions/', $url);
        Storage::disk('public')->assertExists($path);

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
