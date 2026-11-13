<?php

namespace App\Services\V1\Teacher;

use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Models\Course;
use App\Models\CourseLeague;
use App\Models\GroupEnrollment;
use App\Models\LeagueParticipant;
use App\Models\StudyGroup;
use App\Models\User;
use App\Repositories\Contracts\CourseLeagueRepositoryInterface;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TeacherLeagueService extends BaseService
{
    public function __construct(
        protected CourseLeagueRepositoryInterface $leagueRepository
    ) {}

    /**
     * List all leagues created by the teacher.
     */
    public function listLeagues(User $teacher, array $filters = []): Collection
    {
        return $this->leagueRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Retrieve single league details with all participants.
     */
    public function getLeague(User $teacher, string $uuid): CourseLeague
    {
        $league = $this->leagueRepository->findByUuidForTeacher($uuid, $teacher);

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
        if (!empty($courseIdentifier)) {
            $course = Course::where('uuid', $courseIdentifier)
                ->orWhere('id', is_numeric($courseIdentifier) ? (int) $courseIdentifier : 0)
                ->first();
        }

        if (!$course) {
            // Fallback to teacher's first course if course_id is empty or was not found
            $course = Course::where('teacher_id', $teacher->id)->first();
        }

        if (!$course) {
            throw ValidationException::withMessages([
                'course_id' => ['لم يتم العثور على أي مقرر دراسي للمدرس. يرجى إنشاء مقرر دراسي أولاً للبدء في تنظيم الدوري.'],
            ]);
        }
        $course->assertOwnedBy($teacher);

        // 2. Resolve study group if custom group mode
        $studyGroupId = null;
        $groupNameAr = 'جميع المجموعات (دوري موحد)';
        $groupIdentifier = $data['study_group_id'] ?? null;
        if (!empty($groupIdentifier) && $groupIdentifier !== 'all') {
            /** @var StudyGroup|null $group */
            $group = StudyGroup::where('uuid', $groupIdentifier)
                ->orWhere('id', is_numeric($groupIdentifier) ? (int) $groupIdentifier : 0)
                ->first();

            if ($group) {
                $group->assertOwnedBy($teacher);
                $studyGroupId = $group->id;
                $groupNameAr = $group->name_ar;
            }
        }

        // 3. Compute duration and dates
        $durationStr = strtolower(trim((string) ($data['duration'] ?? 'monthly')));
        $durationEnum = LeagueDuration::tryFrom($durationStr) ?? LeagueDuration::MONTHLY;
        $durationDays = $durationEnum->durationDays();

        $startsAt = !empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : Carbon::now();
        $endsAt = (clone $startsAt)->addDays($durationDays);

        $titleAr = trim((string) ($data['title_ar'] ?? ''));
        if ($titleAr === '') {
            $titleAr = "دوري {$course->title_ar} - " . ($teacher->name ?? 'المدرس');
        }

        return $this->transaction(function () use (
            $teacher, $course, $studyGroupId, $groupNameAr, $titleAr,
            $durationEnum, $durationDays, $startsAt, $endsAt, $data
        ) {
            /** @var CourseLeague $league */
            $league = CourseLeague::create([
                'teacher_id'     => $teacher->id,
                'course_id'      => $course->id,
                'study_group_id' => $studyGroupId,
                'title_ar'       => $titleAr,
                'course_name_ar' => $course->title_ar,
                'group_name_ar'  => $groupNameAr,
                'duration_type'  => $durationEnum,
                'duration_days'  => $durationDays,
                'is_active'      => isset($data['is_active']) ? (bool) $data['is_active'] : true,
                'starts_at'      => $startsAt,
                'ends_at'        => $endsAt,
            ]);

            // 4. Enroll participants from group/course enrollments
            $enrollmentsQuery = GroupEnrollment::where('status', 'enrolled')
                ->whereHas('group', function ($q) use ($teacher, $studyGroupId) {
                    $q->where('teacher_id', $teacher->id);
                    if ($studyGroupId) {
                        $q->where('id', $studyGroupId);
                    }
                })
                ->with(['student', 'group']);

            $enrollments = $enrollmentsQuery->get();

            // Insert initial participants
            $seenStudentIds = [];
            foreach ($enrollments as $enrollment) {
                if (in_array($enrollment->student_id, $seenStudentIds, true)) {
                    continue;
                }
                $seenStudentIds[] = $enrollment->student_id;

                $initialXp = 1000 + ($enrollment->streak_days * 20);

                LeagueParticipant::create([
                    'course_league_id'    => $league->id,
                    'student_id'          => $enrollment->student_id,
                    'rank'                => 1,
                    'previous_rank'       => null,
                    'xp_points'           => $initialXp,
                    'streak_days'         => (int) $enrollment->streak_days,
                    'badge_title_ar'      => 'منافس نشط',
                    'tier'                => LeagueTier::resolveFromXp($initialXp)->value,
                    'movement'            => 'same',
                    'school_ar'           => 'مدرسة المتفوقين',
                    'group_ar'            => $enrollment->group?->name_ar ?? $groupNameAr,
                    'perfect_exams_count' => 0,
                    'exams_solved_count'  => 0,
                    'homework_rate'       => (int) $enrollment->attendance_rate,
                    'attendance_rate'     => (int) $enrollment->attendance_rate,
                ]);
            }

            // Recalculate ranks, tiers and badges
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'course_league_created',
                [
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'title_ar'    => $league->title_ar,
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
                $updates['is_active'] = (bool) $data['is_active'];
            }

            if (array_key_exists('duration', $data)) {
                $durationEnum = LeagueDuration::tryFrom(strtolower(trim((string) $data['duration']))) ?? LeagueDuration::MONTHLY;
                $durationDays = $durationEnum->durationDays();
                $startsAt = $league->starts_at ? Carbon::parse($league->starts_at) : Carbon::now();

                $updates['duration_type'] = $durationEnum;
                $updates['duration_days'] = $durationDays;
                $updates['ends_at'] = (clone $startsAt)->addDays($durationDays);
            }

            if (array_key_exists('ends_at', $data) && !empty($data['ends_at'])) {
                $endsAt = Carbon::parse($data['ends_at'])->endOfDay();
                $startsAt = $league->starts_at ? Carbon::parse($league->starts_at) : Carbon::now();
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

                    if (!$group) {
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

            if (!empty($updates)) {
                $league->update($updates);
            }

            if ($syncEnrollments) {
                $enrollments = GroupEnrollment::where('status', 'enrolled')
                    ->whereHas('group', function ($q) use ($teacher, $newStudyGroupId) {
                        $q->where('teacher_id', $teacher->id);
                        if ($newStudyGroupId) {
                            $q->where('id', $newStudyGroupId);
                        }
                    })
                    ->with(['group'])
                    ->get();

                foreach ($enrollments as $enrollment) {
                    $initialXp = 1000 + ((int) $enrollment->streak_days * 20);
                    LeagueParticipant::firstOrCreate(
                        [
                            'course_league_id' => $league->id,
                            'student_id'       => $enrollment->student_id,
                        ],
                        [
                            'rank'                => 1,
                            'previous_rank'       => null,
                            'xp_points'           => $initialXp,
                            'streak_days'         => (int) $enrollment->streak_days,
                            'badge_title_ar'      => 'منافس نشط',
                            'tier'                => LeagueTier::resolveFromXp($initialXp)->value,
                            'movement'            => 'same',
                            'school_ar'           => 'مدرسة المتفوقين',
                            'group_ar'            => $enrollment->group?->name_ar ?? ($updates['group_name_ar'] ?? $league->group_name_ar),
                            'perfect_exams_count' => 0,
                            'exams_solved_count'  => 0,
                            'homework_rate'       => (int) $enrollment->attendance_rate,
                            'attendance_rate'     => (int) $enrollment->attendance_rate,
                        ]
                    );
                }
            }

            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'course_league_updated',
                [
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'updates'     => array_keys($updates),
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

        if (!$student || !$student->isStudent()) {
            throw ValidationException::withMessages([
                'student_id' => [__('messages.student_not_found')],
            ]);
        }

        $alreadyExists = LeagueParticipant::where('course_league_id', $league->id)
            ->where('student_id', $student->id)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'student_id' => ['هذا الطالب مشترك بالفعل في هذا الدوري.'],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league, $student, $data) {
            $initialXp = isset($data['initial_xp']) ? max(0, (int) $data['initial_xp']) : 1000;

            $enrollment = GroupEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->whereHas('group', fn ($q) => $q->where('teacher_id', $teacher->id))
                ->with('group')
                ->first();

            $groupName = !empty($data['group_ar'])
                ? (string) $data['group_ar']
                : ($enrollment?->group?->name_ar ?? $league->group_name_ar ?? 'جميع المجموعات');

            LeagueParticipant::create([
                'course_league_id'    => $league->id,
                'student_id'          => $student->id,
                'rank'                => 1,
                'previous_rank'       => null,
                'xp_points'           => $initialXp,
                'streak_days'         => (int) ($enrollment?->streak_days ?? 0),
                'badge_title_ar'      => 'منافس نشط',
                'tier'                => LeagueTier::resolveFromXp($initialXp)->value,
                'movement'            => 'same',
                'school_ar'           => 'مدرسة المتفوقين',
                'group_ar'            => $groupName,
                'perfect_exams_count' => 0,
                'exams_solved_count'  => 0,
                'homework_rate'       => (int) ($enrollment?->attendance_rate ?? 0),
                'attendance_rate'     => (int) ($enrollment?->attendance_rate ?? 0),
            ]);

            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_participant_added',
                [
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'student_id'  => $student->id,
                    'initial_xp'  => $initialXp,
                ],
                'info',
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

        $studentIdentifier = $data['student_id'] ?? null;
        /** @var User|null $student */
        $student = User::where('id', is_numeric($studentIdentifier) ? (int) $studentIdentifier : 0)
            ->first();

        if (!$student) {
            throw ValidationException::withMessages([
                'student_id' => [__('messages.student_not_found')],
            ]);
        }

        return $this->transaction(function () use ($teacher, $league, $student, $data) {
            /** @var LeagueParticipant $participant */
            $participant = LeagueParticipant::firstOrCreate(
                [
                    'course_league_id' => $league->id,
                    'student_id'       => $student->id,
                ],
                [
                    'rank'           => 1,
                    'xp_points'      => 1000,
                    'badge_title_ar' => 'منافس نشط',
                    'tier'           => 'Bronze',
                    'movement'       => 'same',
                ]
            );

            $xpToAdd = max(1, (int) ($data['xp_amount'] ?? 50));
            $participant->increment('xp_points', $xpToAdd);

            // Re-order and refresh all participants
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_xp_awarded',
                [
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'student_id'  => $student->id,
                    'xp_amount'   => $xpToAdd,
                    'reason_ar'   => $data['reason_ar'] ?? 'مكافأة تشجيعية',
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
            $league->recalculateRanksAndMetrics();

            $this->logSecurityEvent(
                'league_recalculated',
                [
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
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
                    'teacher_id'  => $teacher->id,
                    'league_uuid' => $league->uuid,
                    'title_ar'    => $league->title_ar,
                ],
                'warning',
                $league
            );

            $league->participants()->delete();

            return (bool) $league->delete();
        });
    }
}

