<?php

namespace App\Services\V1\Teacher;

use App\Enums\CourseStatus;
use App\Enums\DirectPaymentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\TeacherPlanTier;
use App\Models\Course;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\StudentDirectPayment;
use App\Models\StudentLessonProgress;
use App\Models\User;
use App\Repositories\Contracts\CourseRepositoryInterface;
use App\Repositories\Contracts\TeacherProfileRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;

class TeacherCourseService extends BaseService
{
    public function __construct(
        protected CourseRepositoryInterface $courseRepository,
        protected TeacherProfileRepositoryInterface $profileRepository
    ) {}

    /**
     * List all courses owned by the authenticated teacher with batched live metrics (zero N+1).
     */
    public function listCourses(User $teacher, array $filters = []): Collection
    {
        $courses = $this->courseRepository->getForTeacher($teacher->id, $filters);

        return $this->hydrateLiveMetrics($teacher, $courses);
    }

    /**
     * Return plan quota & teacher default subject metadata for the courses header & create modal.
     *
     * @return array<string, mixed>
     */
    public function getCoursesMeta(User $teacher, int $filteredTotal): array
    {
        $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);
        $planTier = $profile->plan_tier ?? TeacherPlanTier::PLUS;
        $activeCoursesCount = $this->courseRepository->countActiveForTeacher($teacher->id);

        return [
            'total' => $filteredTotal,
            'activeCoursesCount' => $activeCoursesCount,
            'maxCourses' => $planTier->maxCourses(),
            'planTier' => $planTier->value,
            'defaultSubjectAr' => $profile->subject_ar ?: 'المادة الدراسية',
            'defaultSubjectEn' => $profile->subject_en ?: ($profile->subject_ar ?: 'Subject'),
        ];
    }

    /**
     * Retrieve a single course owned by the authenticated teacher with live metrics.
     */
    public function getCourse(User $teacher, string $uuid): Course
    {
        $course = $this->courseRepository->findByUuidForTeacher($uuid, $teacher);
        $this->hydrateLiveMetrics($teacher, new Collection([$course]));

        return $course;
    }

    /**
     * Create a new curriculum course for the teacher.
     */
    public function createCourse(User $teacher, array $data): Course
    {
        $course = $this->transaction(function () use ($teacher, $data) {
            $profile = $this->profileRepository->firstOrCreateForTeacher($teacher);

            $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title'] ?? ''));
            $titleEn = trim((string) ($data['title_en'] ?? $data['titleEn'] ?? $data['title'] ?? $titleAr));

            $gradeAr = trim((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade'] ?? 'الصف الثالث الثانوي'));
            $rawStage = trim((string) ($data['stage_id'] ?? $data['stageId'] ?? $data['stage_type'] ?? $data['stageType'] ?? $data['stage'] ?? 'sec'));
            [$stageId, $stageNameAr] = $this->resolveStageInfo($rawStage, $gradeAr);

            $subjectAr = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject'] ?? $profile->subject_ar ?? 'الأحياء'));
            if ($subjectAr === '') {
                $subjectAr = $profile->subject_ar ?: 'الأحياء';
            }
            $subjectEn = trim((string) ($data['subject_en'] ?? $data['subjectEn'] ?? $profile->subject_en ?? $subjectAr));

            $priceEgp = (float) ($data['price_egp'] ?? $data['priceEgp'] ?? $data['price'] ?? 0);
            $descriptionAr = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description'] ?? ''));
            $descriptionEn = trim((string) ($data['description_en'] ?? $data['descriptionEn'] ?? $descriptionAr));

            $hoursTotal = trim((string) ($data['hours_total'] ?? $data['hoursTotal'] ?? $data['hours'] ?? '0 ساعة'));
            $lessonsCount = max(0, (int) ($data['lessons_count'] ?? $data['lessonsCount'] ?? 0));
            $status = CourseStatus::tryFrom((string) ($data['status'] ?? 'published')) ?? CourseStatus::PUBLISHED;

            /** @var Course $created */
            $created = $this->courseRepository->create([
                'teacher_id' => $teacher->id,
                'title_ar' => $titleAr,
                'title_en' => $titleEn,
                'stage_id' => $stageId,
                'stage_name_ar' => $stageNameAr,
                'grade_ar' => $gradeAr,
                'subject_ar' => $subjectAr,
                'subject_en' => $subjectEn,
                'price_egp' => $priceEgp,
                'description_ar' => $descriptionAr,
                'description_en' => $descriptionEn,
                'cover_url' => $data['cover_url'] ?? $data['cover'] ?? null,
                'hours_total' => $hoursTotal !== '' ? $hoursTotal : '0 ساعة',
                'lessons_count' => $lessonsCount,
                'status' => $status,
            ]);

            $this->logSecurityEvent(
                'teacher.course.created',
                [
                    'course_uuid' => $created->uuid,
                    'title_ar' => $created->title_ar,
                    'stage_id' => $created->stage_id,
                    'grade_ar' => $created->grade_ar,
                    'price_egp' => $created->price_egp,
                ],
                'info',
                $created
            );

            return $created->fresh();
        });

        $this->hydrateLiveMetrics($teacher, new Collection([$course]));

        return $course;
    }

    /**
     * Update an existing course owned by the teacher.
     */
    public function updateCourse(User $teacher, string $uuid, array $data): Course
    {
        // Enforce ownership BEFORE opening transaction so anti-IDOR audit logs are never rolled back
        $course = $this->courseRepository->findByUuidForTeacher($uuid, $teacher);

        $updated = $this->transaction(function () use ($course, $data) {
            $updates = [];

            if (array_key_exists('title_ar', $data) || array_key_exists('titleAr', $data) || array_key_exists('title', $data)) {
                $titleAr = trim((string) ($data['title_ar'] ?? $data['titleAr'] ?? $data['title']));
                if ($titleAr !== '') {
                    $updates['title_ar'] = $titleAr;
                }
            }

            if (array_key_exists('title_en', $data) || array_key_exists('titleEn', $data)) {
                $updates['title_en'] = trim((string) ($data['title_en'] ?? $data['titleEn']));
            } elseif (isset($updates['title_ar']) && ! array_key_exists('title_en', $data)) {
                $updates['title_en'] = $updates['title_ar'];
            }

            if (array_key_exists('grade_ar', $data) || array_key_exists('gradeAr', $data) || array_key_exists('grade', $data)) {
                $updates['grade_ar'] = trim((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? $data['grade']));
            }

            if (
                array_key_exists('stage_id', $data)
                || array_key_exists('stageId', $data)
                || array_key_exists('stage_type', $data)
                || array_key_exists('stageType', $data)
                || array_key_exists('stage', $data)
                || isset($updates['grade_ar'])
            ) {
                $rawStage = trim((string) (
                    $data['stage_id']
                    ?? $data['stageId']
                    ?? $data['stage_type']
                    ?? $data['stageType']
                    ?? $data['stage']
                    ?? $course->stage_id
                ));
                [$stageId, $stageNameAr] = $this->resolveStageInfo($rawStage, $updates['grade_ar'] ?? $course->grade_ar);
                $updates['stage_id'] = $stageId;
                $updates['stage_name_ar'] = $stageNameAr;
            }

            if (array_key_exists('subject_ar', $data) || array_key_exists('subjectAr', $data) || array_key_exists('subject', $data)) {
                $subjectAr = trim((string) ($data['subject_ar'] ?? $data['subjectAr'] ?? $data['subject']));
                if ($subjectAr !== '') {
                    $updates['subject_ar'] = $subjectAr;
                    if (! array_key_exists('subject_en', $data) && ! array_key_exists('subjectEn', $data)) {
                        $updates['subject_en'] = $subjectAr;
                    }
                }
            }

            if (array_key_exists('subject_en', $data) || array_key_exists('subjectEn', $data)) {
                $updates['subject_en'] = trim((string) ($data['subject_en'] ?? $data['subjectEn']));
            }

            if (array_key_exists('price_egp', $data) || array_key_exists('priceEgp', $data) || array_key_exists('price', $data)) {
                $updates['price_egp'] = max(0.0, (float) ($data['price_egp'] ?? $data['priceEgp'] ?? $data['price']));
            }

            if (array_key_exists('description_ar', $data) || array_key_exists('descriptionAr', $data) || array_key_exists('description', $data)) {
                $updates['description_ar'] = trim((string) ($data['description_ar'] ?? $data['descriptionAr'] ?? $data['description']));
            }

            if (array_key_exists('description_en', $data) || array_key_exists('descriptionEn', $data)) {
                $updates['description_en'] = trim((string) ($data['description_en'] ?? $data['descriptionEn']));
            }

            if (array_key_exists('hours_total', $data) || array_key_exists('hoursTotal', $data) || array_key_exists('hours', $data)) {
                $hours = trim((string) ($data['hours_total'] ?? $data['hoursTotal'] ?? $data['hours']));
                if ($hours !== '') {
                    $updates['hours_total'] = $hours;
                }
            }

            if (array_key_exists('lessons_count', $data) || array_key_exists('lessonsCount', $data)) {
                $updates['lessons_count'] = max(0, (int) ($data['lessons_count'] ?? $data['lessonsCount']));
            }

            if (array_key_exists('status', $data)) {
                $status = CourseStatus::tryFrom((string) $data['status']);
                if ($status) {
                    $updates['status'] = $status;
                }
            }

            if (! empty($updates)) {
                $course->update($updates);
            }

            $this->logSecurityEvent(
                'teacher.course.updated',
                [
                    'course_uuid' => $course->uuid,
                    'updated_fields' => array_keys($updates),
                ],
                'info',
                $course
            );

            return $course->fresh();
        });

        $this->hydrateLiveMetrics($teacher, new Collection([$updated]));

        return $updated;
    }

    /**
     * Soft-delete a course owned by the teacher.
     */
    public function deleteCourse(User $teacher, string $uuid): void
    {
        // Enforce ownership BEFORE opening transaction so anti-IDOR audit logs are never rolled back
        $course = $this->courseRepository->findByUuidForTeacher($uuid, $teacher);

        $this->transaction(function () use ($course) {
            $courseUuid = $course->uuid;
            $titleAr = $course->title_ar;

            $course->delete();

            $this->logSecurityEvent(
                'teacher.course.deleted',
                [
                    'course_uuid' => $courseUuid,
                    'title_ar' => $titleAr,
                ],
                'warning'
            );
        });
    }

    /**
     * Batch-hydrate real live counts (lessons, duration, enrolled students, confirmed revenue, completion rate)
     * across a collection of courses in 4 indexed SQL queries (zero N+1).
     */
    protected function hydrateLiveMetrics(User $teacher, Collection $courses): Collection
    {
        if ($courses->isEmpty()) {
            return $courses;
        }

        $courseIds = $courses->pluck('id')->all();

        // 1. Real non-deleted lessons count + total duration seconds per course
        $lessonStats = Lesson::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('course_id', $courseIds)
            ->selectRaw('course_id, COUNT(*) as lessons_count, COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->groupBy('course_id')
            ->get()
            ->keyBy('course_id');

        // 2. Real distinct enrolled students via study groups linked to each course
        $studentStats = GroupEnrollment::query()
            ->join('study_groups as sg', 'sg.id', '=', 'group_enrollments.study_group_id')
            ->where('sg.teacher_id', $teacher->id)
            ->whereNull('sg.deleted_at')
            ->whereIn('sg.course_id', $courseIds)
            ->where('group_enrollments.status', EnrollmentStatus::ENROLLED->value)
            ->selectRaw('sg.course_id as course_id, COUNT(DISTINCT group_enrollments.student_id) as students_count')
            ->groupBy('sg.course_id')
            ->pluck('students_count', 'course_id');

        // 3. Real confirmed direct payments revenue per course
        $revenueStats = StudentDirectPayment::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('course_id', $courseIds)
            ->where('status', DirectPaymentStatus::CONFIRMED->value)
            ->selectRaw('course_id, COALESCE(SUM(amount_egp), 0) as revenue_egp')
            ->groupBy('course_id')
            ->pluck('revenue_egp', 'course_id');

        // 4. Real student completion rate per course
        $completionStats = StudentLessonProgress::query()
            ->whereIn('course_id', $courseIds)
            ->selectRaw('course_id, AVG(progress_percent) as avg_progress')
            ->groupBy('course_id')
            ->pluck('avg_progress', 'course_id');

        foreach ($courses as $course) {
            $lStat = $lessonStats->get($course->id);
            $course->setAttribute('live_lessons_count', (int) ($lStat?->lessons_count ?? 0));
            $course->setAttribute('live_duration_seconds', (int) ($lStat?->duration_seconds ?? 0));
            $course->setAttribute('live_students_count', (int) ($studentStats->get($course->id) ?? 0));
            $course->setAttribute('live_revenue_egp', (float) ($revenueStats->get($course->id) ?? 0.0));
            $course->setAttribute('live_completion_rate', $completionStats->has($course->id) ? (float) $completionStats->get($course->id) : null);
        }

        return $courses;
    }

    /**
     * Normalize stage code and Arabic display name.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveStageInfo(string $rawStage, ?string $gradeAr = null): array
    {
        $normalized = strtolower($rawStage);

        if ($gradeAr && str_contains($gradeAr, 'الإعدادي')) {
            return ['prep', 'المرحلة الإعدادية'];
        }

        if ($gradeAr && str_contains($gradeAr, 'الابتدائي')) {
            return ['prim', 'المرحلة الابتدائية'];
        }

        if ($gradeAr && str_contains($gradeAr, 'الثانوي')) {
            return ['sec', 'المرحلة الثانوية'];
        }

        if (in_array($normalized, ['prep', 'stage-prep'], true) || $rawStage === 'المرحلة الإعدادية') {
            return ['prep', 'المرحلة الإعدادية'];
        }

        if (in_array($normalized, ['prim', 'primary', 'stage-primary'], true) || $rawStage === 'المرحلة الابتدائية') {
            return ['prim', 'المرحلة الابتدائية'];
        }

        return ['sec', 'المرحلة الثانوية'];
    }
}
