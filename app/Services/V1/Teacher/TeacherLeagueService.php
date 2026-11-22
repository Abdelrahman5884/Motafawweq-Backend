<?php

namespace App\Services\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\GroupEnrollment;
use App\Models\LeagueParticipant;
use App\Models\StudentXpLedger;
use App\Models\StudyGroup;
use App\Models\User;
use App\Repositories\Contracts\CourseLeagueRepositoryInterface;
use App\Services\BaseService;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TeacherLeagueService extends BaseService
{
    public function __construct(
        protected CourseLeagueRepositoryInterface $leagueRepository,
        protected LeagueRulesService $leagueRulesService
    ) {}

    /**
     * List all leagues created by the teacher.
     */
    public function listLeagues(User $teacher, array $filters = []): Collection
    {
        CourseLeague::forTeacher($teacher->id)
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', Carbon::now())
            ->update(['is_active' => false]);

        return $this->leagueRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Retrieve single league details with all participants.
     */
    public function getLeague(User $teacher, string $uuid): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($uuid, $teacher);

        if ($league->is_active && $league->ends_at && $league->ends_at->isPast()) {
            $league->update(['is_active' => false]);
        }

        return $league->load(['course', 'studyGroup', 'participants.student']);
    }

    /**
     * Create a new league, calculate season duration, and populate enrolled student participants.
     */
    public function createLeague(User $teacher, array $data): CourseLeague
    {
        // 1. Resolve and authorize course
        $courseIdentifier = $data['course_id'] ?? null;
        /** @var Course|null $course */
        $course = null;
        if (! empty($courseIdentifier)) {
            $course = Course::where('uuid', $courseIdentifier)
                ->orWhere('id', is_numeric($courseIdentifier) ? (int) $courseIdentifier : 0)
                ->first();

            if (! $course) {
                throw ValidationException::withMessages([
                    'course_id' => ['المقرر الدراسي المحدد غير موجود.'],
                ]);
            }
        } else {
            // Fallback to teacher's first course if course_id was omitted
            $course = Course::where('teacher_id', $teacher->id)->first();
        }

        if (! $course) {
            throw ValidationException::withMessages([
                'course_id' => ['لم يتم العثور على أي مقرر دراسي للمدرس. يرجى إنشاء مقرر دراسي أولاً للبدء في تنظيم الدوري.'],
            ]);
        }
        $course->assertOwnedBy($teacher);

        // 2. Resolve study group if custom group mode
        $studyGroupId = null;
        $groupNameAr = 'جميع المجموعات (دوري موحد)';
        $groupIdentifier = $data['study_group_id'] ?? null;
        if (! empty($groupIdentifier) && $groupIdentifier !== 'all') {
            /** @var StudyGroup|null $group */
            $group = StudyGroup::where('uuid', $groupIdentifier)
                ->orWhere('id', is_numeric($groupIdentifier) ? (int) $groupIdentifier : 0)
                ->first();

            if (! $group) {
                throw ValidationException::withMessages([
                    'study_group_id' => ['المجموعة الدراسية المحددة غير موجودة.'],
                ]);
            }

            $group->assertOwnedBy($teacher);
            $studyGroupId = $group->id;
            $groupNameAr = $group->name_ar;
        }

        // 3. Compute duration and dates
        $durationStr = strtolower(trim((string) ($data['duration'] ?? 'monthly')));
        $durationEnum = LeagueDuration::tryFrom($durationStr) ?? LeagueDuration::MONTHLY;
        $durationDays = $durationEnum->durationDays();

        $startsAt = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : Carbon::now();
        $endsAt = (clone $startsAt)->addDays($durationDays);

        $titleAr = trim((string) ($data['title_ar'] ?? ''));
        if ($titleAr === '') {
            $titleAr = "دوري {$course->title_ar} - ".($teacher->name ?? 'المدرس');
        }

        return $this->transaction(function () use (
            $teacher, $course, $studyGroupId, $groupNameAr, $titleAr,
            $durationEnum, $durationDays, $startsAt, $endsAt, $data
        ) {
            /** @var CourseLeague $league */
            $league = CourseLeague::create([
                'teacher_id' => $teacher->id,
                'course_id' => $course->id,
                'study_group_id' => $studyGroupId,
                'title_ar' => $titleAr,
                'course_name_ar' => $course->title_ar,
                'group_name_ar' => $groupNameAr,
                'duration_type' => $durationEnum,
                'duration_days' => $durationDays,
                'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            // 4. Enroll participants from matching group/course enrollments
            $enrollmentsQuery = GroupEnrollment::where('status', 'enrolled')
                ->whereHas('group', function ($q) use ($teacher, $studyGroupId, $course) {
                    $q->where('teacher_id', $teacher->id);
                    if ($studyGroupId) {
                        $q->where('id', $studyGroupId);
                    } else {
                        $q->where(function ($sub) use ($course) {
                            $sub->where('course_id', $course->id)
                                ->orWhereNull('course_id');
                        });
                    }
                })
                ->with(['student', 'group']);

            $enrollments = $enrollmentsQuery->get();

            // Every new league starts fresh at 0 points for all participants (NO pre-league points carried over!)
            $uniqueEnrollments = $enrollments->unique('student_id');
            $metricsMap = $this->leagueRulesService->computeMetricsForStudents(
                $uniqueEnrollments->pluck('student_id')->all(),
                $teacher->id,
                $course->id,
                $startsAt,
                $endsAt
            );

            foreach ($uniqueEnrollments as $enrollment) {
                $m = $metricsMap[$enrollment->student_id] ?? null;
                $streakDays = (int) ($m['streakDays'] ?? $enrollment->streak_days ?? 0);

                LeagueParticipant::create([
                    'course_league_id' => $league->id,
                    'student_id' => $enrollment->student_id,
                    'rank' => 1,
                    'previous_rank' => null,
                    'xp_points' => 0,
                    'streak_days' => $streakDays,
                    'badge_title_ar' => 'منافس نشط',
                    'tier' => LeagueTier::BRONZE->value,
                    'movement' => 'same',
                    'school_ar' => $m['schoolAr'] ?: null,
                    'group_ar' => $enrollment->group?->name_ar ?? $groupNameAr,
                    'perfect_exams_count' => 0,
                    'exams_solved_count' => 0,
                    'homework_rate' => 0,
                    'attendance_rate' => (int) ($m['attendanceRate'] ?? $enrollment->attendance_rate ?? 0),
                ]);
            }

            // Recalculate ranks, tiers and badges
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'course_league_created',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'title_ar' => $league->title_ar,
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Update an existing league's title, duration, end date, target group, or status.
     */
    public function updateLeague(User $teacher, string $leagueUuid, array $data): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        return $this->transaction(function () use ($teacher, $league, $data) {
            $updates = [];

            if (array_key_exists('title_ar', $data) && trim((string) $data['title_ar']) !== '') {
                $updates['title_ar'] = trim((string) $data['title_ar']);
            }

            if (array_key_exists('is_active', $data)) {
                $requestedActive = (bool) $data['is_active'];
                if ($requestedActive && $league->isCrowned()) {
                    throw ValidationException::withMessages([
                        'is_active' => ['لا يمكن إعادة تفعيل دوري تم إعلان تتويجه وإيقافه نهائياً.'],
                    ]);
                }
                $updates['is_active'] = $requestedActive;
            }

            if (array_key_exists('duration', $data)) {
                $durationEnum = LeagueDuration::tryFrom(strtolower(trim((string) $data['duration']))) ?? LeagueDuration::MONTHLY;
                $durationDays = $durationEnum->durationDays();
                $startsAt = $league->starts_at ? Carbon::parse($league->starts_at) : Carbon::now();

                $updates['duration_type'] = $durationEnum;
                $updates['duration_days'] = $durationDays;
                $updates['ends_at'] = (clone $startsAt)->addDays($durationDays);
            }

            if (array_key_exists('ends_at', $data) && ! empty($data['ends_at'])) {
                $endsAt = Carbon::parse($data['ends_at'])->endOfDay();
                $startsAt = $league->starts_at ? Carbon::parse($league->starts_at) : Carbon::now();
                if ($endsAt->lt($startsAt->copy()->startOfDay())) {
                    throw ValidationException::withMessages([
                        'ends_at' => ['تاريخ انتهاء الدوري يجب أن يكون بعد تاريخ بدايته.'],
                    ]);
                }
                $updates['ends_at'] = $endsAt;
                $updates['duration_days'] = max(1, (int) $startsAt->diffInDays($endsAt, false));
            }

            $newStudyGroupId = $league->study_group_id;
            $syncEnrollments = false;

            if (array_key_exists('study_group_id', $data)) {
                $groupIdentifier = $data['study_group_id'];
                if (empty($groupIdentifier) || $groupIdentifier === 'all') {
                    $newStudyGroupId = null;
                    $updates['study_group_id'] = null;
                    $updates['group_name_ar'] = 'جميع المجموعات (دوري موحد)';
                    $syncEnrollments = true;
                } else {
                    /** @var StudyGroup|null $group */
                    $group = StudyGroup::where('uuid', $groupIdentifier)
                        ->orWhere('id', is_numeric($groupIdentifier) ? (int) $groupIdentifier : 0)
                        ->first();

                    if (! $group) {
                        throw ValidationException::withMessages([
                            'study_group_id' => ['المجموعة الدراسية المحددة غير موجودة.'],
                        ]);
                    }
                    $group->assertOwnedBy($teacher);

                    $newStudyGroupId = $group->id;
                    $updates['study_group_id'] = $group->id;
                    $updates['group_name_ar'] = $group->name_ar;
                    $syncEnrollments = true;
                }
            }

            if (! empty($updates)) {
                $league->update($updates);
            }

            if ($syncEnrollments) {
                $enrollments = GroupEnrollment::where('status', 'enrolled')
                    ->whereHas('group', function ($q) use ($teacher, $newStudyGroupId, $league) {
                        $q->where('teacher_id', $teacher->id);
                        if ($newStudyGroupId) {
                            $q->where('id', $newStudyGroupId);
                        } elseif ($league->course_id) {
                            $q->where(function ($sub) use ($league) {
                                $sub->where('course_id', $league->course_id)
                                    ->orWhereNull('course_id');
                            });
                        }
                    })
                    ->with(['group'])
                    ->get();

                $existingStudentIds = LeagueParticipant::withTrashed()
                    ->where('course_league_id', $league->id)
                    ->pluck('student_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $uniqueEnrollments = $enrollments
                    ->unique('student_id')
                    ->filter(fn (GroupEnrollment $e) => ! in_array((int) $e->student_id, $existingStudentIds, true));

                $metricsMap = $this->leagueRulesService->computeMetricsForStudents(
                    $uniqueEnrollments->pluck('student_id')->all(),
                    $teacher->id,
                    $league->course_id ? (int) $league->course_id : null,
                    $league->starts_at,
                    $league->ends_at
                );

                foreach ($uniqueEnrollments as $enrollment) {
                    $m = $metricsMap[$enrollment->student_id] ?? null;
                    $streakDays = (int) ($m['streakDays'] ?? $enrollment->streak_days ?? 0);

                    LeagueParticipant::create([
                        'course_league_id' => $league->id,
                        'student_id' => $enrollment->student_id,
                        'rank' => 1,
                        'previous_rank' => null,
                        'xp_points' => 0,
                        'streak_days' => $streakDays,
                        'badge_title_ar' => 'منافس نشط',
                        'tier' => LeagueTier::BRONZE->value,
                        'movement' => 'same',
                        'school_ar' => $m['schoolAr'] ?: null,
                        'group_ar' => $enrollment->group?->name_ar ?? ($updates['group_name_ar'] ?? $league->group_name_ar),
                        'perfect_exams_count' => (int) ($m['perfectExams'] ?? 0),
                        'exams_solved_count' => (int) ($m['examsSolved'] ?? 0),
                        'homework_rate' => (int) ($m['homeworkRate'] ?? 0),
                        'attendance_rate' => (int) ($m['attendanceRate'] ?? $enrollment->attendance_rate ?? 0),
                    ]);
                }
            }

            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'course_league_updated',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'updates' => array_keys($updates),
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Add a student participant to an active/existing league after it has started.
     */
    public function addParticipant(User $teacher, string $leagueUuid, array $data): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        if ($league->isConcluded()) {
            throw ValidationException::withMessages([
                'league' => ['لا يمكن إضافة طلاب إلى دوري منتهي أو تم إعلان تتويجه.'],
            ]);
        }

        $identifier = trim((string) ($data['student_id'] ?? ''));
        /** @var User|null $student */
        $student = User::where(function ($q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('student_code', $identifier);
            }
        })->first();

        if (! $student || ! $student->isStudent()) {
            throw ValidationException::withMessages([
                'student_id' => [__('messages.student_not_found')],
            ]);
        }

        /** @var LeagueParticipant|null $existingParticipant */
        $existingParticipant = LeagueParticipant::withTrashed()
            ->where('course_league_id', $league->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existingParticipant && ! $existingParticipant->trashed()) {
            throw ValidationException::withMessages([
                'student_id' => ['هذا الطالب مشترك بالفعل في هذا الدوري.'],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league, $student, $data, $existingParticipant) {
            $metrics = $this->leagueRulesService->computeMetricsForStudents(
                [$student->id],
                $teacher->id,
                $league->course_id ? (int) $league->course_id : null,
                $league->starts_at,
                $league->ends_at
            )[$student->id] ?? null;

            $initialXp = array_key_exists('initial_xp', $data) && $data['initial_xp'] !== null
                ? max(0, (int) $data['initial_xp'])
                : 0;

            $enrollment = GroupEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->whereHas('group', fn ($q) => $q->where('teacher_id', $teacher->id))
                ->with('group')
                ->first();

            $groupName = ! empty($data['group_ar'])
                ? (string) $data['group_ar']
                : ($enrollment?->group?->name_ar ?? $league->group_name_ar ?? 'جميع المجموعات');

            $attributes = [
                'rank' => 1,
                'previous_rank' => null,
                'xp_points' => $initialXp,
                'streak_days' => (int) ($metrics['streakDays'] ?? $enrollment?->streak_days ?? 0),
                'badge_title_ar' => 'منافس نشط',
                'tier' => LeagueTier::resolveFromXp($initialXp)->value,
                'movement' => 'same',
                'school_ar' => $metrics['schoolAr'] ?: null,
                'group_ar' => $groupName,
                'perfect_exams_count' => (int) ($metrics['perfectExams'] ?? 0),
                'exams_solved_count' => (int) ($metrics['examsSolved'] ?? 0),
                'homework_rate' => (int) ($metrics['homeworkRate'] ?? 0),
                'attendance_rate' => (int) ($metrics['attendanceRate'] ?? $enrollment?->attendance_rate ?? 0),
            ];

            if ($existingParticipant && $existingParticipant->trashed()) {
                $existingParticipant->restore();
                $existingParticipant->update($attributes);
            } else {
                LeagueParticipant::create(array_merge([
                    'course_league_id' => $league->id,
                    'student_id' => $student->id,
                ], $attributes));
            }

            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_participant_added',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'student_id' => $student->id,
                    'initial_xp' => $initialXp,
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Remove a participant from a league and recalculate ranks.
     */
    public function removeParticipant(User $teacher, string $leagueUuid, string $participantIdentifier): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        if ($league->isCrowned()) {
            throw ValidationException::withMessages([
                'league' => ['لا يمكن إزالة متنافسين من دوري تم إعلان تتويجه نهائياً.'],
            ]);
        }

        $identifier = trim($participantIdentifier);
        /** @var LeagueParticipant|null $participant */
        $participant = LeagueParticipant::where('course_league_id', $league->id)
            ->where(function ($q) use ($identifier) {
                $q->where('uuid', $identifier);
                if (is_numeric($identifier)) {
                    $q->orWhere('student_id', (int) $identifier);
                } else {
                    $q->orWhereHas('student', fn ($s) => $s->where('uuid', $identifier)->orWhere('student_code', $identifier));
                }
            })
            ->first();

        if (! $participant) {
            throw ValidationException::withMessages([
                'participant_id' => ['الطالب المحدد غير مشارك في هذا الدوري.'],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league, $participant) {
            $studentId = $participant->student_id;
            $participant->delete();
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_participant_removed',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'student_id' => $studentId,
                ],
                'warning',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Award bonus XP to a participant inside the league and update leaderboard.
     */
    public function awardXp(User $teacher, string $leagueUuid, array $data): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        if ($league->isConcluded()) {
            throw ValidationException::withMessages([
                'league' => ['لا يمكن منح نقاط بونص لدوري منتهي أو تم إعلان تتويجه.'],
            ]);
        }

        $studentIdentifier = trim((string) ($data['student_id'] ?? ''));
        /** @var User|null $student */
        $student = User::where(function ($q) use ($studentIdentifier) {
            if (is_numeric($studentIdentifier)) {
                $q->where('id', (int) $studentIdentifier);
            } else {
                $q->where('uuid', $studentIdentifier)
                    ->orWhere('student_code', $studentIdentifier);
            }
        })->first();

        if (! $student || ! $student->isStudent()) {
            throw ValidationException::withMessages([
                'student_id' => [__('messages.student_not_found')],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league, $student, $data) {
            $metrics = $this->leagueRulesService->computeMetricsForStudents(
                [$student->id],
                $teacher->id,
                $league->course_id ? (int) $league->course_id : null,
                $league->starts_at,
                $league->ends_at
            )[$student->id] ?? null;

            /** @var LeagueParticipant|null $participant */
            $participant = LeagueParticipant::withTrashed()
                ->where('course_league_id', $league->id)
                ->where('student_id', $student->id)
                ->first();

            if ($participant && $participant->trashed()) {
                $participant->restore();
                $participant->update([
                    'xp_points' => 0,
                    'previous_rank' => null,
                    'movement' => 'same',
                ]);
            } elseif (! $participant) {
                $participant = LeagueParticipant::create([
                    'course_league_id' => $league->id,
                    'student_id' => $student->id,
                    'rank' => 1,
                    'xp_points' => 0,
                    'streak_days' => (int) ($metrics['streakDays'] ?? 0),
                    'badge_title_ar' => 'منافس نشط',
                    'tier' => LeagueTier::BRONZE->value,
                    'movement' => 'same',
                    'school_ar' => $metrics['schoolAr'] ?: null,
                    'group_ar' => $metrics['groupAr'] ?: $league->group_name_ar,
                    'perfect_exams_count' => (int) ($metrics['perfectExams'] ?? 0),
                    'exams_solved_count' => (int) ($metrics['examsSolved'] ?? 0),
                    'homework_rate' => (int) ($metrics['homeworkRate'] ?? 0),
                    'attendance_rate' => (int) ($metrics['attendanceRate'] ?? 0),
                ]);
            }

            $xpToAdd = max(1, (int) ($data['xp_amount'] ?? 50));
            $participant->increment('xp_points', $xpToAdd);

            $nextBonusId = ((int) StudentXpLedger::where('student_id', $student->id)
                ->where('source_type', 'teacher_league_bonus')
                ->max('source_id')) + 1;

            StudentXpLedger::awardOnce(
                $student->id,
                'teacher_league_bonus',
                $nextBonusId,
                $xpToAdd,
                trim((string) ($data['reason_ar'] ?? "مكافأة المعلم في {$league->title_ar}"))
            );

            // Re-order and refresh all participants
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_xp_awarded',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'student_id' => $student->id,
                    'xp_amount' => $xpToAdd,
                    'reason_ar' => $data['reason_ar'] ?? 'مكافأة تشجيعية',
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Re-calculate all participants' ranks, badges, tiers and movement flags.
     */
    public function recalculateLeague(User $teacher, string $leagueUuid): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        return $this->transaction(function () use ($teacher, $league) {
            $this->leagueRulesService->syncCourseLeague($league);
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_recalculated',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Officially crown winners, permanently stop the league, and unlock league certificates.
     */
    public function crownLeague(User $teacher, string $leagueUuid): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        if ($league->isCrowned()) {
            throw ValidationException::withMessages([
                'league' => ['تم إعلان تتويج هذا الدوري وإيقافه مسبقاً.'],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league) {
            // Final sync & ranking lock before stopping the league
            $this->leagueRulesService->syncCourseLeague($league);
            $league->recalculateRanksAndMetrics();

            if ($league->participants()->count() === 0) {
                throw ValidationException::withMessages([
                    'league' => ['لا يمكن إعلان تتويج دوري لا يحتوي على طلاب مشاركين.'],
                ]);
            }

            $now = Carbon::now();
            $finalEndsAt = ($league->ends_at && $league->ends_at->isPast())
                ? $league->ends_at
                : $now;

            $league->update([
                'is_active' => false,
                'crowned_at' => $now,
                'ends_at' => $finalEndsAt,
            ]);

            $this->logSecurityEvent(
                'course_league_crowned',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'total_competitors' => (int) $league->total_competitors,
                ],
                'info',
                $league
            );

            return $league->fresh(['course', 'studyGroup', 'participants.student']);
        });
    }

    /**
     * Delete a league and soft-delete its participants.
     */
    public function deleteLeague(User $teacher, string $leagueUuid): bool
    {
        $league = $this->leagueRepository->findByUuidForTeacher($leagueUuid, $teacher);

        return $this->transaction(function () use ($teacher, $league) {
            $this->logSecurityEvent(
                'league_deleted',
                [
                    'teacher_id' => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'title_ar' => $league->title_ar,
                ],
                'warning',
                $league
            );

            $league->participants()->delete();

            return (bool) $league->delete();
        });
    }
}
