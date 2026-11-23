<?php

namespace App\Services\V1\Admin;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminCourseService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService
    ) {}

    /**
     * List all platform courses for moderation with single-pass summary KPIs and zero N+1 queries.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listCourses(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $stage = isset($filters['stage']) ? trim((string) $filters['stage']) : null;
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $featured = $filters['featured'] ?? null;

        $query = Course::query()
            ->with(['teacher:id,name,email,avatar', 'teacher.teacherProfile:id,user_id,name_ar,name_en,rating'])
            ->withCount(['lessons', 'studyGroups'])
            ->latest('updated_at');

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($safe): void {
                $q->where('title_ar', 'like', "%{$safe}%")
                    ->orWhere('title_en', 'like', "%{$safe}%")
                    ->orWhere('subject_ar', 'like', "%{$safe}%")
                    ->orWhere('subject_en', 'like', "%{$safe}%")
                    ->orWhereHas('teacher', fn (Builder $tq) => $tq->where('name', 'like', "%{$safe}%"));
            });
        }

        if ($stage !== null && $stage !== '' && $stage !== 'all') {
            $safeStage = addcslashes($stage, '%_\\');
            $query->where(function (Builder $q) use ($safeStage): void {
                $q->where('grade_ar', $safeStage)
                    ->orWhere('stage_name_ar', 'like', "%{$safeStage}%")
                    ->orWhere('stage_id', $safeStage);
            });
        }

        if ($status !== null && $status !== '' && $status !== 'all') {
            $mappedStatus = $status === 'review' ? CourseStatus::DRAFT->value : $status;
            if (in_array($mappedStatus, CourseStatus::values(), true)) {
                $query->where('status', $mappedStatus);
            }
        }

        if ($featured !== null && $featured !== '') {
            $query->where('is_featured', filter_var($featured, FILTER_VALIDATE_BOOLEAN));
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage);

        $statusCounts = Course::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $featuredCount = (int) Course::query()->where('is_featured', true)->count();
        $totalEnrolled = (int) Course::query()->sum('students_count');

        return [
            'summary' => [
                'total' => array_sum(array_map('intval', $statusCounts)),
                'published' => (int) ($statusCounts[CourseStatus::PUBLISHED->value] ?? 0),
                'review' => (int) ($statusCounts[CourseStatus::DRAFT->value] ?? 0),
                'archived' => (int) ($statusCounts[CourseStatus::ARCHIVED->value] ?? 0),
                'featured' => $featuredCount,
                'totalEnrolled' => $totalEnrolled,
            ],
            'items' => collect($paginator->items())
                ->map(fn (Course $c) => $this->formatCourse($c))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Get single course details with lessons list.
     *
     * @return array<string, mixed>
     */
    public function getCourse(string $uuid): array
    {
        /** @var Course $course */
        $course = Course::query()
            ->where('uuid', $uuid)
            ->with([
                'teacher:id,name,email,phone,avatar',
                'teacher.teacherProfile:id,user_id,name_ar,name_en,rating',
                'lessons:id,uuid,course_id,title_ar,title_en,duration_seconds,status,created_at',
            ])
            ->withCount(['lessons', 'studyGroups'])
            ->firstOrFail();

        $data = $this->formatCourse($course);
        $data['descriptionAr'] = $course->description_ar;
        $data['descriptionEn'] = $course->description_en;
        $data['moderationNotes'] = $course->moderation_notes;
        $data['moderatedAt'] = $course->moderated_at?->toIso8601String();
        $data['lessons'] = $course->lessons->map(fn ($l) => [
            'id' => $l->uuid,
            'titleAr' => $l->title_ar,
            'titleEn' => $l->title_en ?: $l->title_ar,
            'durationSeconds' => (int) $l->duration_seconds,
            'status' => is_object($l->status) ? $l->status->value : (string) $l->status,
            'createdAt' => $l->created_at?->toDateString(),
        ])->values()->all();

        return $data;
    }

    /**
     * Toggle a course between published and draft (review) status.
     *
     * @return array<string, mixed>
     */
    public function togglePublish(User $admin, string $uuid, ?string $notes = null): array
    {
        /** @var Course $course */
        $course = Course::query()
            ->where('uuid', $uuid)
            ->with(['teacher:id,name,email,avatar', 'teacher.teacherProfile:id,user_id,name_ar,name_en,rating'])
            ->withCount(['lessons', 'studyGroups'])
            ->firstOrFail();

        $nextStatus = $course->status === CourseStatus::PUBLISHED
            ? CourseStatus::DRAFT
            : CourseStatus::PUBLISHED;

        $course->forceFill([
            'status' => $nextStatus,
            'moderation_notes' => $notes ?? $course->moderation_notes,
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
        ])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.course.publish_toggled', [
            'admin_id' => $admin->id,
            'course_uuid' => $course->uuid,
            'teacher_id' => $course->teacher_id,
            'new_status' => $nextStatus->value,
        ], 'info', $course);

        return $this->formatCourse($course->fresh(['teacher:id,name,email,avatar', 'teacher.teacherProfile:id,user_id,name_ar,name_en,rating'])->loadCount(['lessons', 'studyGroups']));
    }

    /**
     * Toggle featured marketplace flag on a course.
     *
     * @return array<string, mixed>
     */
    public function toggleFeatured(User $admin, string $uuid): array
    {
        /** @var Course $course */
        $course = Course::query()->where('uuid', $uuid)->firstOrFail();

        $nextFeatured = ! (bool) $course->is_featured;
        $course->forceFill([
            'is_featured' => $nextFeatured,
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
        ])->save();

        $this->settingsService->bumpCacheEpoch();

        $this->logSecurityEvent('admin.course.featured_toggled', [
            'admin_id' => $admin->id,
            'course_uuid' => $course->uuid,
            'is_featured' => $nextFeatured,
        ], 'info', $course);

        return $this->formatCourse($course->fresh(['teacher:id,name,email,avatar', 'teacher.teacherProfile:id,user_id,name_ar,name_en,rating'])->loadCount(['lessons', 'studyGroups']));
    }

    /**
     * Soft-delete a course with moderation reason and immutable audit log.
     */
    public function deleteCourse(User $admin, string $uuid, ?string $reason = null): void
    {
        /** @var Course $course */
        $course = Course::query()->where('uuid', $uuid)->firstOrFail();

        $this->transaction(function () use ($admin, $course, $reason): void {
            $course->forceFill([
                'status' => CourseStatus::ARCHIVED,
                'is_featured' => false,
                'moderation_notes' => $reason ?: $course->moderation_notes,
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
            ])->save();

            $course->delete();

            $this->logSecurityEvent('admin.course.deleted', [
                'admin_id' => $admin->id,
                'course_uuid' => $course->uuid,
                'teacher_id' => $course->teacher_id,
                'reason' => $reason,
            ], 'warning', $course);
        });

        $this->settingsService->bumpCacheEpoch();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCourse(Course $c): array
    {
        $statusVal = $c->status instanceof CourseStatus ? $c->status->value : (string) $c->status;
        $uiStatus = $statusVal === CourseStatus::DRAFT->value ? 'review' : $statusVal;
        $instructorAr = $c->teacher?->teacherProfile?->name_ar ?: ($c->teacher?->name ?? 'معلم');
        $instructorEn = $c->teacher?->teacherProfile?->name_en ?: $instructorAr;

        return [
            'id' => $c->uuid,
            'teacherId' => $c->teacher_id,
            'title' => $c->title_ar,
            'titleAr' => $c->title_ar,
            'titleEn' => $c->title_en ?: $c->title_ar,
            'instructor' => $instructorAr,
            'instructorAr' => $instructorAr,
            'instructorEn' => $instructorEn,
            'stage' => $c->grade_ar ?: $c->stage_name_ar,
            'stageAr' => $c->grade_ar ?: $c->stage_name_ar,
            'stageEn' => $c->grade_ar ?: $c->stage_name_ar,
            'stageId' => $c->stage_id,
            'subject' => $c->subject_ar,
            'subjectAr' => $c->subject_ar,
            'subjectEn' => $c->subject_en ?: $c->subject_ar,
            'enrolled' => (int) $c->students_count,
            'priceEgp' => (float) $c->price_egp,
            'revenueEgp' => (float) $c->revenue_egp,
            'rating' => round((float) ($c->rating ?: ($c->teacher?->teacherProfile?->rating ?? 5.0)), 2),
            'status' => $uiStatus,
            'rawStatus' => $statusVal,
            'isFeatured' => (bool) $c->is_featured,
            'totalLectures' => (int) ($c->lessons_count ?? 0),
            'studyGroupsCount' => (int) ($c->study_groups_count ?? 0),
            'lastUpdate' => $c->updated_at?->toDateString(),
        ];
    }
}
