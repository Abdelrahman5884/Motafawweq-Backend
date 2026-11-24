<?php

namespace Tests\Feature\Admin;

use App\Enums\TokenType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityAuditLog;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMinistryCurriculumTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_ministry_curricula_cms_endpoints(): void
    {
        $student = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($student, [TokenType::ACCESS->value]);

        $this->getJson('/api/v1/admin/ministry-curricula')->assertForbidden();
        $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'اختبار غير مصرح',
            'subjectAr' => 'الرياضيات',
            'gradeAr' => 'الصف الأول الابتدائي',
        ])->assertForbidden();
    }

    public function test_admin_can_list_all_twelve_grades_and_create_edit_publish_delete_ministry_curriculum_synced_with_students(): void
    {
        $admin = User::factory()->admin()->create();
        $pri1Student = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        StudentProfile::create([
            'student_id' => $pri1Student->id,
            'stage' => 'stage-primary',
            'grade_ar' => 'الصف الأول الابتدائي',
            'track_ar' => 'عام',
        ]);

        // 1. Admin lists seeded Ministry curricula covering all 12 grades (1st Primary -> 3rd Secondary)
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $listRes = $this->getJson('/api/v1/admin/ministry-curricula')
            ->assertOk()
            ->assertJsonPath('meta.summary.totalGradesCount', 12)
            ->assertJsonPath('meta.summary.coveredGradesCount', 12)
            ->assertJsonCount(12, 'meta.gradeCatalog');

        $this->assertGreaterThanOrEqual(12, $listRes->json('meta.summary.total'));

        // Filter by Primary stage
        $priRes = $this->getJson('/api/v1/admin/ministry-curricula?stage=stage-primary')
            ->assertOk();
        $this->assertNotEmpty($priRes->json('data'));
        foreach ($priRes->json('data') as $row) {
            $this->assertSame('stage-primary', $row['stage']);
        }

        // 2. Admin creates a brand new 1st Primary curriculum with YouTube videos + written study content
        $createRes = $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'الرياضيات التأسيسية — الصف الأول الابتدائي <b>مطور</b>',
            'subjectAr' => 'الرياضيات',
            'subjectEn' => 'Mathematics',
            'gradeAr' => 'الصف الأول الابتدائي',
            'term' => 'first',
            'tracks' => ['عام', 'لغات'],
            'descriptionAr' => 'منهج تأسيس الأعداد والجمع والطرح للصف الأول الابتدائي.',
            'textbookUrl' => 'https://elearning1.moe.gov.eg/primary1-math',
            'isPublished' => true,
            'units' => [
                [
                    'titleAr' => 'الوحدة الأولى: الأعداد من 1 إلى 10',
                    'summaryAr' => 'شرح مبسط لعد الأرقام وكتابتها بطريقة ممتعة.',
                    'chaptersCount' => 3,
                    'topics' => ['العد حتى 5', 'العد حتى 10', 'المقارنة بين الأعداد'],
                    'videos' => [
                        [
                            'titleAr' => 'شرح الأعداد من 1 إلى 5',
                            'youtubeUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=12s',
                            'durationMinutes' => 14,
                            'notesAr' => 'تمرين منزلي بعد المشاهدة',
                        ],
                        [
                            'titleAr' => 'مراجعة قصيرة على الأعداد',
                            'youtubeUrl' => 'https://youtu.be/M7lc1UVf-VE?si=abc123',
                            'durationMinutes' => 8,
                        ],
                    ],
                    'resources' => [
                        [
                            'titleAr' => 'كراسة تدريبات الوحدة الأولى',
                            'type' => 'pdf',
                            'url' => 'https://elearning1.moe.gov.eg/worksheets/p1-u1.pdf',
                            'contentAr' => 'حل التمارين من صفحة 1 إلى صفحة 5.',
                        ],
                    ],
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.gradeAr', 'الصف الأول الابتدائي')
            ->assertJsonPath('data.stage', 'stage-primary')
            ->assertJsonPath('data.term', 'first')
            ->assertJsonPath('data.videosCount', 2)
            ->assertJsonPath('data.resourcesCount', 1)
            ->assertJsonPath('data.units.0.videos.0.youtubeId', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.units.0.videos.0.embedUrl', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->assertJsonPath('data.units.0.videos.1.youtubeId', 'M7lc1UVf-VE');

        // XSS tags stripped cleanly
        $this->assertStringNotContainsString('<b>', (string) $createRes->json('data.titleAr'));
        $uuid = (string) $createRes->json('data.uuid');
        $code = (string) $createRes->json('data.code');

        $this->assertTrue(SecurityAuditLog::where('event', 'admin.ministry_curriculum.created')->exists());

        // 3. Student in 1st Primary IMMEDIATELY sees the published curriculum with YouTube videos & can track progress
        Sanctum::actingAs($pri1Student, [TokenType::ACCESS->value]);
        $studentList = $this->getJson('/api/v1/student/ministry/curricula')
            ->assertOk()
            ->assertJsonPath('data.selectedGradeAr', 'الصف الأول الابتدائي');

        $matching = collect($studentList->json('data.items'))->firstWhere('code', $code);
        $this->assertNotNull($matching);
        $this->assertSame(2, $matching['videosCount']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $matching['units'][0]['videos'][0]['embedUrl']);
        $this->assertSame('كراسة تدريبات الوحدة الأولى', $matching['units'][0]['resources'][0]['titleAr']);

        // Student marks unit 0 completed -> 100% progress
        $this->postJson("/api/v1/student/ministry/curricula/{$code}/units/0/toggle")
            ->assertOk()
            ->assertJsonPath('data.progressPercent', 100);

        // 4. Admin updates content (adds a YouTube Shorts video and second unit)
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->putJson("/api/v1/admin/ministry-curricula/{$uuid}", [
            'titleAr' => 'الرياضيات التأسيسية المحدثة — الصف الأول الابتدائي',
            'units' => [
                [
                    'titleAr' => 'الوحدة الأولى: الأعداد من 1 إلى 10',
                    'videos' => [
                        [
                            'titleAr' => 'فيديو شورتس سريع',
                            'youtubeUrl' => 'https://www.youtube.com/shorts/aqz-KE-bpKQ',
                            'durationMinutes' => 1,
                        ],
                    ],
                ],
                [
                    'titleAr' => 'الوحدة الثانية: الجمع البسيط',
                    'summaryAr' => 'شرح الجمع باستخدام الأصابع والخط العددي.',
                    'topics' => ['الجمع حتى 10'],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.unitsCount', 2)
            ->assertJsonPath('data.videosCount', 1)
            ->assertJsonPath('data.units.0.videos.0.youtubeId', 'aqz-KE-bpKQ');

        // 5. Admin unpublishes curriculum -> hidden from student portal immediately
        $this->patchJson("/api/v1/admin/ministry-curricula/{$uuid}/toggle-publish")
            ->assertOk()
            ->assertJsonPath('data.isPublished', false);

        Sanctum::actingAs($pri1Student, [TokenType::ACCESS->value]);
        $afterHide = $this->getJson('/api/v1/student/ministry/curricula')->assertOk();
        $this->assertNull(collect($afterHide->json('data.items'))->firstWhere('code', $code));

        // 6. Admin soft-deletes curriculum with audit log
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/admin/ministry-curricula/{$uuid}", [
            'reason' => 'تحديث طبعة العام الجديد',
        ])->assertOk();

        $this->assertSoftDeleted('ministry_curricula', ['uuid' => $uuid]);
        $this->assertTrue(SecurityAuditLog::where('event', 'admin.ministry_curriculum.deleted')->exists());
    }

    public function test_ministry_curriculum_blocks_invalid_youtube_urls_ssrf_and_invalid_grades(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);

        // Rejects non-YouTube / XSS video URL
        $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'منهج اختبار أمان',
            'subjectAr' => 'العلوم',
            'gradeAr' => 'الصف الثالث الإعدادي',
            'units' => [
                [
                    'titleAr' => 'وحدة 1',
                    'videos' => [
                        ['titleAr' => 'فيديو خبيث', 'youtubeUrl' => 'javascript:alert(1)'],
                    ],
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['units.0.videos.0.youtubeUrl']);

        // Rejects non-YouTube external domain in video field
        $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'منهج اختبار دومين',
            'subjectAr' => 'العلوم',
            'gradeAr' => 'الصف الثالث الإعدادي',
            'units' => [
                [
                    'titleAr' => 'وحدة 1',
                    'videos' => [
                        ['titleAr' => 'فيديو خارجي', 'youtubeUrl' => 'https://evil.example.com/watch?v=dQw4w9WgXcQ'],
                    ],
                ],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['units.0.videos.0.youtubeUrl']);

        // Rejects SSRF localhost URL in textbookUrl
        $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'منهج اختبار SSRF',
            'subjectAr' => 'العلوم',
            'gradeAr' => 'الصف الثالث الإعدادي',
            'textbookUrl' => 'http://127.0.0.1:8000/secret',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['textbookUrl']);

        // Rejects invalid grade
        $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'منهج صف غير معروف',
            'subjectAr' => 'العلوم',
            'gradeAr' => 'الصف الجامعي الأول',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['gradeAr']);
    }

    public function test_two_step_course_creation_then_unit_and_lesson_management_workflow(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create([
            'role' => UserRole::STUDENT,
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => now(),
        ]);
        StudentProfile::create([
            'student_id' => $student->id,
            'stage' => 'stage-primary',
            'grade_ar' => 'الصف الثاني الابتدائي',
            'track_ar' => 'عام',
        ]);

        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);

        // Step 1: Create course metadata alone (0 units initially)
        $courseRes = $this->postJson('/api/v1/admin/ministry-curricula', [
            'titleAr' => 'اللغة الإنجليزية — الصف الثاني الابتدائي',
            'subjectAr' => 'اللغة الإنجليزية',
            'gradeAr' => 'الصف الثاني الابتدائي',
            'term' => 'first',
        ])->assertCreated()
            ->assertJsonPath('data.unitsCount', 0)
            ->assertJsonPath('data.lessonsCount', 0);

        $uuid = (string) $courseRes->json('data.uuid');
        $code = (string) $courseRes->json('data.code');

        // Step 2A: Enter course & add first YouTube lesson -> auto-creates Unit 1
        $addLes1 = $this->postJson("/api/v1/admin/ministry-curricula/{$uuid}/lessons", [
            'unitTitleAr' => 'Unit 1: Meet My Family',
            'titleAr' => 'الحصة الأولى: أفراد العائلة والتحيات',
            'youtubeUrl' => 'https://youtu.be/dQw4w9WgXcQ',
            'durationMinutes' => 18,
            'contentAr' => 'أهم الكلمات: Father, Mother, Sister, Brother.',
            'attachments' => [
                ['titleAr' => 'ملزمة الكلمات PDF', 'type' => 'pdf', 'url' => 'https://elearning1.moe.gov.eg/u1.pdf'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.unitsCount', 1)
            ->assertJsonPath('data.lessonsCount', 1)
            ->assertJsonPath('data.videosCount', 1)
            ->assertJsonPath('data.units.0.lessons.0.youtubeId', 'dQw4w9WgXcQ');

        $lesson1Id = (string) $addLes1->json('data.units.0.lessons.0.id');

        // Step 2B: Add a written-content-only lesson (no YouTube link needed!)
        $addLes2 = $this->postJson("/api/v1/admin/ministry-curricula/{$uuid}/lessons", [
            'unitIndex' => 0,
            'titleAr' => 'الحصة الثانية: تدريبات وقواعد المضارع البسيط',
            'contentAr' => 'شرح قاعدة المضارع البسيط مع أمثلة تطبيقية.',
        ])->assertCreated()
            ->assertJsonPath('data.lessonsCount', 2)
            ->assertJsonPath('data.videosCount', 1);

        $lesson2Id = (string) $addLes2->json('data.units.0.lessons.1.id');

        // Step 2C: Reorder lessons (move Lesson 2 before Lesson 1) & update Lesson 1
        $this->patchJson("/api/v1/admin/ministry-curricula/{$uuid}/units/0/lessons/reorder", [
            'lessonIds' => [$lesson2Id, $lesson1Id],
        ])->assertOk()
            ->assertJsonPath('data.units.0.lessons.0.id', $lesson2Id);

        $this->putJson("/api/v1/admin/ministry-curricula/{$uuid}/units/0/lessons/{$lesson1Id}", [
            'titleAr' => 'الحصة الأولى (محدثة): العائلة والتحيات',
            'durationMinutes' => 22,
        ])->assertOk()
            ->assertJsonPath('data.units.0.lessons.1.durationMinutes', 22);

        // Step 2D: Add a second Unit explicitly
        $this->postJson("/api/v1/admin/ministry-curricula/{$uuid}/units", [
            'titleAr' => 'Unit 2: My Body',
            'summaryAr' => 'التعرف على الحواس الخمس.',
        ])->assertCreated()
            ->assertJsonPath('data.unitsCount', 2);

        // Verify Student in 2nd Primary immediately sees all units & lessons (video + written)
        Sanctum::actingAs($student, [TokenType::ACCESS->value]);
        $stuRes = $this->getJson('/api/v1/student/ministry/curricula')->assertOk();
        $found = collect($stuRes->json('data.items'))->firstWhere('code', $code);
        $this->assertNotNull($found);
        $this->assertSame(2, $found['lessonsCount']);
        $this->assertCount(2, $found['units'][0]['lessons']);

        // Delete Lesson 2 cleanly
        Sanctum::actingAs($admin, [TokenType::ACCESS->value]);
        $this->deleteJson("/api/v1/admin/ministry-curricula/{$uuid}/units/0/lessons/{$lesson2Id}")
            ->assertOk()
            ->assertJsonPath('data.lessonsCount', 1);
    }
}
