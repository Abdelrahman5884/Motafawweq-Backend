<?php

namespace App\Services\V1\Student;

use App\Enums\CertificateStatus;
use App\Models\CourseCertificate;
use App\Models\CourseLeague;
use App\Models\LeagueParticipant;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StudentGamificationService extends BaseService
{
    public function __construct(
        protected StudentAccessService $accessService
    ) {}

    /**
     * Retrieve student's total server-verified XP, level, badges, and recent immutable XP ledger entries.
     */
    public function getGamificationSummary(User $student): array
    {
        $totalXp = (int) StudentXpLedger::forStudent($student->id)->sum('points');
        $level = max(1, (int) floor($totalXp / 100) + 1);
        $nextLevelXp = $level * 100;

        $recentLedger = StudentXpLedger::forStudent($student->id)
            ->latest('created_at')
            ->limit(25)
            ->get()
            ->map(fn (StudentXpLedger $entry) => [
                'id' => $entry->uuid,
                'sourceType' => $entry->source_type,
                'points' => (int) $entry->points,
                'reasonAr' => $entry->reason_ar,
                'createdAt' => $entry->created_at?->toIso8601String(),
            ])->values()->all();

        return [
            'totalXp' => $totalXp,
            'level' => $level,
            'nextLevelXp' => $nextLevelXp,
            'recentLedger' => $recentLedger,
        ];
    }

    /**
     * List accessible course leagues for the student.
     */
    public function listLeagues(User $student): array
    {
        $entitlements = $this->accessService->getEntitlements($student);
        if (empty($entitlements['teacher_ids']) && empty($entitlements['course_ids'])) {
            return [];
        }

        $leagues = CourseLeague::query()
            ->where(function ($q) use ($entitlements) {
                if (! empty($entitlements['teacher_ids'])) {
                    $q->whereIn('teacher_id', $entitlements['teacher_ids']);
                }
                if (! empty($entitlements['course_ids'])) {
                    $q->orWhereIn('course_id', $entitlements['course_ids']);
                }
            })
            ->withCount('participants')
            ->latest()
            ->get();

        return $leagues->map(fn (CourseLeague $l) => [
            'id' => $l->uuid,
            'titleAr' => $l->title_ar,
            'courseTitleAr' => $l->course_title_ar,
            'tier' => $l->tier?->value ?? (string) $l->tier,
            'participantsCount' => (int) $l->participants_count,
            'isActive' => (bool) $l->is_active,
        ])->values()->all();
    }

    /**
     * Retrieve privacy-preserving leaderboard for an accessible league.
     * Masks student names according to each student's privacy settings and never leaks email/phone.
     */
    public function getLeagueLeaderboard(User $student, string $leagueUuid): array
    {
        /** @var CourseLeague|null $league */
        $league = CourseLeague::where('uuid', $leagueUuid)->first();
        if (! $league) {
            throw (new ModelNotFoundException)->setModel(CourseLeague::class, [$leagueUuid]);
        }

        $this->accessService->assertCanAccess($student, $league, (int) $league->teacher_id, $league->course_id ? (int) $league->course_id : null);

        $participants = LeagueParticipant::query()
            ->where('course_league_id', $league->id)
            ->with('student:id,name')
            ->orderByDesc('xp_points')
            ->limit(100)
            ->get();

        $studentIds = $participants->pluck('student_id')->filter()->unique()->all();
        $profilesByStudent = ! empty($studentIds)
            ? StudentProfile::whereIn('student_id', $studentIds)->get()->keyBy('student_id')
            : collect();

        $leaderboard = [];
        $rank = 1;

        foreach ($participants as $p) {
            $isMe = (int) $p->student_id === (int) $student->id;
            /** @var StudentProfile|null $prof */
            $prof = $p->student_id ? $profilesByStudent->get($p->student_id) : null;
            $rawName = $p->student?->name ?: 'طالب متفوق';

            if (! $isMe && $prof && ! $prof->show_on_leaderboard) {
                $displayName = 'طالب متفوق (مخفي)';
            } elseif (! $isMe && $prof && $prof->display_name_mode === 'nickname' && ! empty($prof->nickname)) {
                $displayName = $prof->nickname;
            } elseif (! $isMe && $prof && $prof->display_name_mode === 'initials') {
                $parts = preg_split('/\s+/u', trim((string) $rawName)) ?: [];
                $first = $parts[0] ?? 'طالب';
                $lastInitial = isset($parts[1]) ? mb_substr($parts[1], 0, 1).'.' : '';
                $displayName = trim("{$first} {$lastInitial}");
            } else {
                $displayName = $rawName;
            }

            $leaderboard[] = [
                'rank' => $rank++,
                'displayName' => $displayName,
                'xpPoints' => (int) $p->xp_points,
                'streakDays' => (int) $p->streak_days,
                'isCurrentUser' => $isMe,
            ];
        }

        return [
            'leagueId' => $league->uuid,
            'titleAr' => $league->title_ar,
            'leaderboard' => $leaderboard,
        ];
    }

    /**
     * List verified certificates issued to the authenticated student.
     */
    public function listCertificates(User $student): array
    {
        $certificates = CourseCertificate::query()
            ->where('student_id', $student->id)
            ->where('status', CertificateStatus::VERIFIED)
            ->latest('issue_date')
            ->get();

        return $certificates->map(fn (CourseCertificate $c) => [
            'id' => $c->uuid,
            'certificateNumber' => $c->certificate_number,
            'signatureHash' => $c->signature_hash,
            'studentNameAr' => $c->student_name_ar,
            'courseNameAr' => $c->course_name_ar,
            'gradeAr' => $c->grade_ar,
            'gradePercent' => (float) $c->grade_percent,
            'honorsTitleAr' => $c->honors_title_ar,
            'issueDate' => $c->issue_date?->format('Y-m-d'),
            'verifyUrl' => "/api/v1/verify-certificate/{$c->certificate_number}",
        ])->values()->all();
    }
}
