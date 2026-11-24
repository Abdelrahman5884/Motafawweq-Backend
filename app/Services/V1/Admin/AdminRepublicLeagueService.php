<?php

namespace App\Services\V1\Admin;

use App\Enums\ExamSubmissionStatus;
use App\Enums\LeagueDuration;
use App\Enums\LeagueTier;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\V1\Admin\SaveAdminChampionRequest;
use App\Models\PlatformChampion;
use App\Models\RepublicLeague;
use App\Models\User;
use App\Services\BaseService;
use App\Services\V1\LeagueRulesService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * High-performance, pure-SQL Republic League management, grade-scoped live standings,
 * and automatic Top-Performer nominee detection for the Platform Admin Portal.
 *
 * Supports all 6 canonical grades from 1st Preparatory through 3rd Secondary,
 * with zero N+1 queries, MySQL ONLY_FULL_GROUP_BY safety, and full server-side pagination.
 */
class AdminRepublicLeagueService extends BaseService
{
    public function __construct(
        protected PlatformSettingsService $settingsService,
        protected LeagueRulesService $leagueRulesService
    ) {}

    /**
     * Overview of the Republic League state, active/recent rounds, and per-grade stats (1st Prep → 3rd Sec).
     *
     * @return array<string, mixed>
     */
    public function getOverview(?string $gradeFilter = null): array
    {
        RepublicLeague::closeExpiredRounds();

        $normalizedGrade = RepublicLeague::normalizeGrade($gradeFilter);

        $activeLeague = RepublicLeague::currentActive($normalizedGrade);
        $latestLeague = $activeLeague ?: $this->resolveLatestLeague($normalizedGrade);

        $activeRounds = RepublicLeague::query()
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN grade_ar IS NULL THEN 0 ELSE 1 END')
            ->latest('starts_at')
            ->get();

        $recentRounds = RepublicLeague::query()
            ->latest('starts_at')
            ->limit(10)
            ->get();

        // Single GROUP BY query for active student counts per grade_ar
        $rawGradeCounts = DB::table('users')
            ->leftJoin('student_profiles', 'student_profiles.student_id', '=', 'users.id')
            ->where('users.role', UserRole::STUDENT->value)
            ->where('users.status', UserStatus::ACTIVE->value)
            ->select([
                'student_profiles.grade_ar',
                DB::raw('COUNT(users.id) as cnt'),
            ])
            ->groupBy('student_profiles.grade_ar')
            ->get();

        $countByCanonicalGrade = array_fill_keys(RepublicLeague::supportedGradeLabels(), 0);
        $totalStudentsAll = 0;

        foreach ($rawGradeCounts as $row) {
            $cnt = (int) $row->cnt;
            $totalStudentsAll += $cnt;
            $canonical = RepublicLeague::normalizeGrade($row->grade_ar);
            if ($canonical !== null && isset($countByCanonicalGrade[$canonical])) {
                $countByCanonicalGrade[$canonical] += $cnt;
            }
        }

        // Single GROUP BY query for pinned champions per grade
        $championsByGrade = PlatformChampion::query()
            ->where('is_pinned', true)
            ->select(['grade_ar', DB::raw('COUNT(*) as cnt')])
            ->groupBy('grade_ar')
            ->pluck('cnt', 'grade_ar');

        $hasGlobalActive = $activeRounds->contains(fn (RepublicLeague $r) => $r->grade_ar === null);
        $activeGradeSet = $activeRounds->pluck('grade_ar')->filter()->flip()->all();

        $gradesCatalog = [];
        foreach (RepublicLeague::SUPPORTED_GRADES as $g) {
            $gradeAr = $g['ar'];
            $gradesCatalog[] = [
                'key' => $g['key'],
                'value' => $gradeAr,
                'labelAr' => $gradeAr,
                'labelEn' => $g['en'],
                'stage' => $g['stage'],
                'studentsCount' => (int) ($countByCanonicalGrade[$gradeAr] ?? 0),
                'hasActiveRound' => $hasGlobalActive || isset($activeGradeSet[$gradeAr]),
                'hasDedicatedRound' => isset($activeGradeSet[$gradeAr]),
                'pinnedChampionsCount' => (int) ($championsByGrade[$gradeAr] ?? 0),
            ];
        }

        // Compute summary counters cleanly for selected scope
        $selectedStudentsCount = $normalizedGrade !== null
            ? (int) ($countByCanonicalGrade[$normalizedGrade] ?? 0)
            : $totalStudentsAll;

        $championSummaryQuery = PlatformChampion::query();
        if ($normalizedGrade !== null) {
            $championSummaryQuery->where('grade_ar', $normalizedGrade);
        }

        $approvedChampionsTotal = (clone $championSummaryQuery)->count();
        $pinnedChampionsTotal = (clone $championSummaryQuery)->where('is_pinned', true)->count();

        // Count active competitors (students with > 0 XP in scope)
        $scoredCount = $this->buildStandingsBaseQuery($normalizedGrade, $latestLeague, '', true)->count();

        // Quick count of pending top nominees (top 3 per grade or top 10 in selected grade not yet pinned)
        $nomineesSnapshot = $this->getAutoNominees([
            'grade' => $normalizedGrade ?? 'all',
            'league_id' => $latestLeague?->uuid,
            'top_per_grade' => $normalizedGrade !== null ? 10 : 3,
            'per_page' => 1,
            'page' => 1,
        ]);

        return [
            'league' => $latestLeague ? $this->formatLeague($latestLeague) : null,
            'activeRounds' => $activeRounds->map(fn (RepublicLeague $r) => $this->formatLeague($r))->values()->all(),
            'recentRounds' => $recentRounds->map(fn (RepublicLeague $r) => $this->formatLeague($r))->values()->all(),
            'grades' => $gradesCatalog,
            'selectedGrade' => $normalizedGrade ?? 'all',
            'summary' => [
                'totalStudents' => $selectedStudentsCount,
                'totalStudentsAllGrades' => $totalStudentsAll,
                'activeCompetitors' => $scoredCount,
                'approvedChampions' => $approvedChampionsTotal,
                'pinnedChampions' => $pinnedChampionsTotal,
                'pendingNominees' => (int) ($nomineesSnapshot['meta']['summary']['pendingCount'] ?? 0),
            ],
        ];
    }

    /**
     * Launch a new Republic League round (platform-wide across all 6 grades OR scoped to a specific grade).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function startLeague(User $admin, array $data): array
    {
        $gradeAr = RepublicLeague::normalizeGrade($data['grade_ar'] ?? null);

        $durationEnum = LeagueDuration::tryFrom((string) ($data['duration'] ?? 'monthly')) ?? LeagueDuration::MONTHLY;
        $durationDays = isset($data['duration_days'])
            ? (int) $data['duration_days']
            : $durationEnum->durationDays();

        $startsAt = Carbon::now();
        $endsAt = $startsAt->copy()->addDays($durationDays);

        $defaultTitle = $gradeAr !== null
            ? "دوري الجمهورية — {$gradeAr}"
            : 'دوري الجمهورية العام (أولى إعدادي — ثالثة ثانوي)';

        $league = $this->transaction(function () use ($admin, $data, $gradeAr, $durationEnum, $durationDays, $startsAt, $endsAt, $defaultTitle): RepublicLeague {
            // Deactivate any active round in the same scope (global or same grade)
            $deactivateQuery = RepublicLeague::query()->where('is_active', true);
            if ($gradeAr === null) {
                $deactivateQuery->whereNull('grade_ar');
            } else {
                $deactivateQuery->where('grade_ar', $gradeAr);
            }
            $deactivateQuery->update(['is_active' => false]);

            return RepublicLeague::create([
                'started_by' => $admin->id,
                'title_ar' => trim((string) ($data['title_ar'] ?? '')) ?: $defaultTitle,
                'grade_ar' => $gradeAr,
                'duration_type' => $durationEnum,
                'duration_days' => $durationDays,
                'is_active' => true,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });

        $this->settingsService->bumpCacheEpoch();
        $this->logSecurityEvent('admin.republic_league.started', [
            'admin_id' => $admin->id,
            'league_uuid' => $league->uuid,
            'grade_ar' => $gradeAr ?? 'all',
            'duration_days' => $durationDays,
        ], 'info', $league);

        return $this->formatLeague($league);
    }

    /**
     * Stop active Republic League round(s) (specific grade, specific round UUID, or all active rounds).
     *
     * @return array{stopped: bool, stoppedCount: int}
     */
    public function stopLeague(User $admin, ?string $gradeFilter = null, ?string $leagueUuid = null): array
    {
        $query = RepublicLeague::query()->where('is_active', true);

        if ($leagueUuid !== null && trim($leagueUuid) !== '') {
            $query->where('uuid', trim($leagueUuid));
        } elseif ($gradeFilter !== null && strtolower(trim($gradeFilter)) !== 'all' && trim($gradeFilter) !== '') {
            $normalized = RepublicLeague::normalizeGrade($gradeFilter);
            if ($normalized !== null) {
                $query->where('grade_ar', $normalized);
            }
        }

        $stoppedCount = $query->update([
            'is_active' => false,
            'ends_at' => Carbon::now(),
        ]);

        $this->settingsService->bumpCacheEpoch();
        $this->logSecurityEvent('admin.republic_league.stopped', [
            'admin_id' => $admin->id,
            'grade_ar' => $gradeFilter ?? 'all',
            'league_uuid' => $leagueUuid,
            'stopped_count' => $stoppedCount,
        ], 'warning');

        return [
            'stopped' => $stoppedCount > 0,
            'stoppedCount' => $stoppedCount,
        ];
    }

    /**
     * Paginated, searchable, grade-filterable Republic League live leaderboard.
     * Uses pure-SQL aggregated subqueries with deterministic ranking and zero N+1 queries.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getLeaderboard(array $filters = []): array
    {
        RepublicLeague::closeExpiredRounds();

        $normalizedGrade = RepublicLeague::normalizeGrade($filters['grade'] ?? null);
        $search = trim((string) ($filters['search'] ?? ''));
        $onlyScored = filter_var($filters['only_scored'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(5, (int) ($filters['per_page'] ?? 15)));

        $league = $this->resolveTargetLeague($filters['league_id'] ?? null, $normalizedGrade);

        $query = $this->buildStandingsBaseQuery($normalizedGrade, $league, $search, $onlyScored);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($paginator->items());

        $studentIds = $rows->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        // Single batch lookup of existing PlatformChampion records for this page's students
        $championsByStudent = $this->loadChampionsForStudents($studentIds, $league?->id);

        // Compute true global/grade rank even when search filters the list
        $trueRankMap = ($search !== '' && ! empty($studentIds))
            ? $this->computeExactRanksForRows($rows, $normalizedGrade, $league)
            : [];

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $items = [];

        foreach ($rows->values() as $idx => $row) {
            $sid = (int) $row->student_id;
            $rank = $trueRankMap[$sid] ?? ($offset + $idx + 1);
            $items[] = $this->formatStandingRow($row, $rank, $league, $championsByStudent->get($sid));
        }

        return [
            'items' => $items,
            'meta' => [
                'league' => $league ? $this->formatLeague($league) : null,
                'selectedGrade' => $normalizedGrade ?? 'all',
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem() ?? 0,
                    'to' => $paginator->lastItem() ?? 0,
                ],
            ],
        ];
    }

    /**
     * Automatically surface Top Republic League achievers pending Admin review/edit/approval for the homepage slider.
     * - When grade = 'all': takes top N performers from EACH of the 6 grades (1st Prep → 3rd Sec).
     * - When a specific grade is selected: takes top performers within that grade.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getAutoNominees(array $filters = []): array
    {
        RepublicLeague::closeExpiredRounds();

        $normalizedGrade = RepublicLeague::normalizeGrade($filters['grade'] ?? null);
        $statusFilter = strtolower(trim((string) ($filters['status'] ?? 'all')));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(30, max(3, (int) ($filters['per_page'] ?? 9)));
        $topPerGrade = min(15, max(1, (int) ($filters['top_per_grade'] ?? ($normalizedGrade !== null ? 10 : 3))));

        $league = $this->resolveTargetLeague($filters['league_id'] ?? null, $normalizedGrade);

        $nomineeCandidates = [];

        if ($normalizedGrade !== null) {
            $gradeRows = $this->buildStandingsBaseQuery($normalizedGrade, $league, '', false)
                ->limit($topPerGrade)
                ->get();

            foreach ($gradeRows->values() as $idx => $row) {
                $nomineeCandidates[] = [
                    'row' => $row,
                    'gradeRank' => $idx + 1,
                ];
            }
        } else {
            // Pull Top N per canonical grade (6 lightweight indexed queries, capped at 3-5 rows each)
            foreach (RepublicLeague::SUPPORTED_GRADES as $g) {
                $gradeLeague = $this->resolveTargetLeague($filters['league_id'] ?? null, $g['ar']) ?? $league;
                $gradeRows = $this->buildStandingsBaseQuery($g['ar'], $gradeLeague, '', false)
                    ->limit($topPerGrade)
                    ->get();

                foreach ($gradeRows->values() as $idx => $row) {
                    $nomineeCandidates[] = [
                        'row' => $row,
                        'gradeRank' => $idx + 1,
                        'league' => $gradeLeague,
                    ];
                }
            }

            // Fallback if no students match canonical grade profiles yet
            if (empty($nomineeCandidates)) {
                $fallbackRows = $this->buildStandingsBaseQuery(null, $league, '', false)
                    ->limit(10)
                    ->get();
                foreach ($fallbackRows->values() as $idx => $row) {
                    $nomineeCandidates[] = [
                        'row' => $row,
                        'gradeRank' => $idx + 1,
                        'league' => $league,
                    ];
                }
            }

            // Sort combined nominees: rank #1s across grades first, then highest XP
            usort($nomineeCandidates, function (array $a, array $b): int {
                if ($a['gradeRank'] !== $b['gradeRank']) {
                    return $a['gradeRank'] <=> $b['gradeRank'];
                }

                $xpDiff = (int) $b['row']->league_xp <=> (int) $a['row']->league_xp;
                if ($xpDiff !== 0) {
                    return $xpDiff;
                }

                return (int) $b['row']->streak_days <=> (int) $a['row']->streak_days;
            });
        }

        $studentIds = array_values(array_unique(array_map(
            fn (array $c) => (int) $c['row']->student_id,
            $nomineeCandidates
        )));

        $championsByStudent = $this->loadChampionsForStudents($studentIds, $league?->id);

        $formattedAll = [];
        $pendingCount = 0;
        $approvedPinnedCount = 0;
        $approvedHiddenCount = 0;

        foreach ($nomineeCandidates as $candidate) {
            $row = $candidate['row'];
            $sid = (int) $row->student_id;
            $rowLeague = $candidate['league'] ?? $league;
            $entry = $this->formatStandingRow(
                $row,
                (int) $candidate['gradeRank'],
                $rowLeague,
                $championsByStudent->get($sid)
            );

            if ($entry['approvalStatus'] === 'approved_pinned') {
                $approvedPinnedCount++;
            } elseif ($entry['approvalStatus'] === 'approved_hidden') {
                $approvedHiddenCount++;
            } else {
                $pendingCount++;
            }

            if ($statusFilter === 'pending' && $entry['approvalStatus'] !== 'pending') {
                continue;
            }
            if ($statusFilter === 'approved' && $entry['approvalStatus'] === 'pending') {
                continue;
            }

            $formattedAll[] = $entry;
        }

        $totalFiltered = count($formattedAll);
        $lastPage = max(1, (int) ceil($totalFiltered / $perPage));
        $safePage = min($page, $lastPage);
        $sliceOffset = ($safePage - 1) * $perPage;
        $pageItems = array_slice($formattedAll, $sliceOffset, $perPage);

        return [
            'items' => array_values($pageItems),
            'meta' => [
                'league' => $league ? $this->formatLeague($league) : null,
                'selectedGrade' => $normalizedGrade ?? 'all',
                'summary' => [
                    'totalNominees' => count($nomineeCandidates),
                    'pendingCount' => $pendingCount,
                    'approvedPinnedCount' => $approvedPinnedCount,
                    'approvedHiddenCount' => $approvedHiddenCount,
                ],
                'pagination' => [
                    'current_page' => $safePage,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $totalFiltered,
                    'from' => $totalFiltered > 0 ? $sliceOffset + 1 : 0,
                    'to' => min($totalFiltered, $sliceOffset + count($pageItems)),
                ],
            ],
        ];
    }

    /**
     * Build the pure-SQL standings query joining active students, profiles, and aggregated XP subqueries.
     * 100% compatible with both MySQL ONLY_FULL_GROUP_BY and SQLite (no LEAST/GROUP BY violations).
     */
    protected function buildStandingsBaseQuery(
        ?string $normalizedGrade,
        ?RepublicLeague $league,
        string $search = '',
        bool $onlyScored = false
    ): Builder {
        $since = $league?->starts_at?->toDateTimeString();
        $until = ($league && ! $league->is_active && $league->ends_at)
            ? $league->ends_at->toDateTimeString()
            : ($league?->ends_at?->toDateTimeString());

        $tfPts = LeagueRulesService::XP_QUESTION_TRUE_FALSE;
        $mcqPts = LeagueRulesService::XP_QUESTION_MCQ;
        $essayPts = LeagueRulesService::XP_QUESTION_ESSAY;
        $fullMarkPts = LeagueRulesService::XP_EXAM_FULL_MARK_BONUS;
        $streakPts = LeagueRulesService::XP_STREAK_PER_DAY;

        // Subquery 1: Graded/submitted correct answers weighted by question type
        $ansAgg = DB::table('exam_submission_answers as esa')
            ->join('exam_submissions as es', 'es.id', '=', 'esa.exam_submission_id')
            ->join('exam_questions as eq', 'eq.id', '=', 'esa.exam_question_id')
            ->whereIn('es.status', [
                ExamSubmissionStatus::SUBMITTED->value,
                ExamSubmissionStatus::GRADED->value,
            ])
            ->where('esa.is_correct', true)
            ->when($since !== null, fn ($q) => $q->where('es.submitted_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('es.submitted_at', '<=', $until))
            ->select([
                'es.student_id',
                DB::raw("SUM(CASE eq.type WHEN 'true_false' THEN {$tfPts} WHEN 'mcq' THEN {$mcqPts} WHEN 'essay' THEN {$essayPts} ELSE {$tfPts} END) as answer_xp"),
            ])
            ->groupBy('es.student_id');

        // Subquery 2: Exam counts & full-mark bonuses
        $exAgg = DB::table('exam_submissions as es2')
            ->whereIn('es2.status', [
                ExamSubmissionStatus::SUBMITTED->value,
                ExamSubmissionStatus::GRADED->value,
            ])
            ->when($since !== null, fn ($q) => $q->where('es2.submitted_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('es2.submitted_at', '<=', $until))
            ->select([
                'es2.student_id',
                DB::raw('COUNT(*) as exams_solved'),
                DB::raw("SUM(CASE WHEN es2.status = 'graded' AND es2.is_full_mark = 1 THEN 1 ELSE 0 END) as perfect_exams"),
            ])
            ->groupBy('es2.student_id');

        // Subquery 3: Window active days for streak scoring
        $actAgg = DB::table('student_daily_activities as sda')
            ->when($since !== null, fn ($q) => $q->where('sda.first_seen_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('sda.first_seen_at', '<=', $until))
            ->select([
                'sda.student_id',
                DB::raw('COUNT(DISTINCT sda.activity_date) as active_days'),
            ])
            ->groupBy('sda.student_id');

        // Subquery 4: Immutable XP ledger (teacher league bonuses + total ledger XP fallback)
        $xpAgg = DB::table('student_xp_ledgers as sxl')
            ->when($since !== null, fn ($q) => $q->where('sxl.created_at', '>=', $since))
            ->when($until !== null, fn ($q) => $q->where('sxl.created_at', '<=', $until))
            ->select([
                'sxl.student_id',
                DB::raw("SUM(CASE WHEN sxl.source_type = 'teacher_league_bonus' THEN sxl.points ELSE 0 END) as bonus_xp"),
                DB::raw('SUM(sxl.points) as ledger_xp'),
            ])
            ->groupBy('sxl.student_id');

        // ANSI SQL expression for window streak (no MySQL-only LEAST or SQLite-only MIN(a,b))
        $isConcluded = $league && (! $league->is_active || ($league->ends_at && $league->ends_at->isPast()));
        if ($since === null) {
            $streakExpr = 'COALESCE(sp.current_streak_days, 0)';
        } elseif ($isConcluded) {
            $streakExpr = 'COALESCE(act_agg.active_days, 0)';
        } else {
            $streakExpr = 'CASE WHEN COALESCE(sp.current_streak_days, 0) < COALESCE(act_agg.active_days, 0) THEN COALESCE(sp.current_streak_days, 0) ELSE COALESCE(act_agg.active_days, 0) END';
        }

        $ruleXpExpr = "(COALESCE(ans_agg.answer_xp, 0) + (COALESCE(ex_agg.perfect_exams, 0) * {$fullMarkPts}) + (({$streakExpr}) * {$streakPts}) + COALESCE(xp_agg.bonus_xp, 0))";
        $leagueXpExpr = "(CASE WHEN {$ruleXpExpr} > 0 THEN {$ruleXpExpr} ELSE COALESCE(xp_agg.ledger_xp, 0) END)";

        $query = DB::table('users')
            ->leftJoin('student_profiles as sp', 'sp.student_id', '=', 'users.id')
            ->leftJoinSub($ansAgg, 'ans_agg', fn ($j) => $j->on('ans_agg.student_id', '=', 'users.id'))
            ->leftJoinSub($exAgg, 'ex_agg', fn ($j) => $j->on('ex_agg.student_id', '=', 'users.id'))
            ->leftJoinSub($actAgg, 'act_agg', fn ($j) => $j->on('act_agg.student_id', '=', 'users.id'))
            ->leftJoinSub($xpAgg, 'xp_agg', fn ($j) => $j->on('xp_agg.student_id', '=', 'users.id'))
            ->where('users.role', UserRole::STUDENT->value)
            ->where('users.status', UserStatus::ACTIVE->value)
            ->select([
                'users.id as student_id',
                'users.name',
                'users.student_code',
                'users.avatar',
                'sp.grade_ar',
                'sp.track_ar',
                'sp.school_name',
                'sp.governorate',
                'sp.center_name',
                DB::raw('COALESCE(sp.current_streak_days, 0) as current_streak_days'),
                DB::raw("{$streakExpr} as streak_days"),
                DB::raw('COALESCE(ex_agg.exams_solved, 0) as exams_solved'),
                DB::raw('COALESCE(ex_agg.perfect_exams, 0) as perfect_exams'),
                DB::raw('COALESCE(xp_agg.ledger_xp, 0) as ledger_xp'),
                DB::raw("{$leagueXpExpr} as league_xp"),
            ]);

        if ($normalizedGrade !== null) {
            $aliases = RepublicLeague::aliasesForGrade($normalizedGrade);
            $query->whereIn('sp.grade_ar', $aliases);
        }

        if ($search !== '') {
            $escaped = addcslashes(mb_strtolower($search), '%_\\');
            $like = "%{$escaped}%";
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(users.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(users.student_code, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sp.school_name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sp.governorate, \'\')) LIKE ?', [$like]);
            });
        }

        if ($onlyScored) {
            $query->whereRaw("{$leagueXpExpr} > 0");
        }

        return $query
            ->orderByRaw("{$leagueXpExpr} DESC")
            ->orderByRaw("{$streakExpr} DESC")
            ->orderByRaw('COALESCE(ex_agg.exams_solved, 0) DESC')
            ->orderBy('users.id');
    }

    /**
     * When searching, compute each displayed student's true rank within the unfiltered grade scope.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    protected function computeExactRanksForRows(
        Collection $rows,
        ?string $normalizedGrade,
        ?RepublicLeague $league
    ): array {
        $ranks = [];

        foreach ($rows as $row) {
            $sid = (int) $row->student_id;
            $xp = (int) $row->league_xp;
            $streak = (int) $row->streak_days;
            $exams = (int) $row->exams_solved;

            $base = $this->buildStandingsBaseQuery($normalizedGrade, $league, '', false);
            $higherCount = DB::query()
                ->fromSub($base, 'standings')
                ->where(function ($q) use ($xp, $streak, $exams, $sid): void {
                    $q->where('league_xp', '>', $xp)
                        ->orWhere(function ($q2) use ($xp, $streak): void {
                            $q2->where('league_xp', '=', $xp)
                                ->where('streak_days', '>', $streak);
                        })
                        ->orWhere(function ($q3) use ($xp, $streak, $exams): void {
                            $q3->where('league_xp', '=', $xp)
                                ->where('streak_days', '=', $streak)
                                ->where('exams_solved', '>', $exams);
                        })
                        ->orWhere(function ($q4) use ($xp, $streak, $exams, $sid): void {
                            $q4->where('league_xp', '=', $xp)
                                ->where('streak_days', '=', $streak)
                                ->where('exams_solved', '=', $exams)
                                ->where('student_id', '<', $sid);
                        });
                })
                ->count();

            $ranks[$sid] = $higherCount + 1;
        }

        return $ranks;
    }

    /**
     * Batch-load existing PlatformChampion records for the given student IDs (prioritizing current league match).
     *
     * @param  list<int>  $studentIds
     * @return Collection<int, PlatformChampion>
     */
    protected function loadChampionsForStudents(array $studentIds, ?int $leagueId): Collection
    {
        if (empty($studentIds)) {
            return collect();
        }

        $records = PlatformChampion::query()
            ->whereIn('student_id', $studentIds)
            ->orderByRaw($leagueId !== null ? 'CASE WHEN republic_league_id = ? THEN 0 ELSE 1 END' : 'CASE WHEN republic_league_id IS NOT NULL THEN 0 ELSE 1 END', $leagueId !== null ? [$leagueId] : [])
            ->orderByDesc('is_pinned')
            ->orderByDesc('id')
            ->get();

        $map = collect();
        foreach ($records as $champ) {
            $sid = (int) $champ->student_id;
            if (! $map->has($sid)) {
                $map->put($sid, $champ);
            }
        }

        return $map;
    }

    /**
     * Format a raw SQL standing row + suggested champion approval payload for the Admin UI.
     *
     * @return array<string, mixed>
     */
    protected function formatStandingRow(
        object $row,
        int $rank,
        ?RepublicLeague $league,
        ?PlatformChampion $existingChampion
    ): array {
        $xp = (int) $row->league_xp;
        $streak = (int) max((int) $row->streak_days, (int) $row->current_streak_days);
        $tierEnum = LeagueTier::resolveFromXp($xp);
        $canonicalGrade = RepublicLeague::normalizeGrade($row->grade_ar) ?? (trim((string) $row->grade_ar) ?: 'الصف الثالث الثانوي');
        $gradeEn = RepublicLeague::gradeLabelEn($canonicalGrade);

        $schoolParts = array_filter([
            trim((string) ($row->school_name ?? '')),
            trim((string) ($row->governorate ?? '')),
        ]);
        $schoolDisplay = ! empty($schoolParts)
            ? implode('، ', $schoolParts)
            : (trim((string) ($row->center_name ?? '')) ?: 'منصة متفوّق التعليمية');

        $approvalStatus = 'pending';
        if ($existingChampion) {
            $approvalStatus = $existingChampion->is_pinned ? 'approved_pinned' : 'approved_hidden';
        }

        [$rankAr, $rankEn] = $this->formatRankLabel($rank, $canonicalGrade);
        [$badgeAr, $badgeEn] = $this->suggestBadgeForRank($rank);

        $formattedXp = number_format($xp);
        $leagueTitleAr = $league?->title_ar ?: 'دوري الجمهورية العام';
        $monthAr = $league?->starts_at
            ? $leagueTitleAr
            : 'دوري الجمهورية '.Carbon::now()->format('Y');

        $defaultImage = $this->resolveSafeAvatarForChampion($row->avatar);

        return [
            'rank' => $rank,
            'studentId' => (int) $row->student_id,
            'studentCode' => $row->student_code ?: 'STD-'.$row->student_id,
            'name' => $row->name,
            'avatar' => $row->avatar ?: null,
            'gradeAr' => $canonicalGrade,
            'gradeEn' => $gradeEn,
            'trackAr' => trim((string) ($row->track_ar ?? '')),
            'schoolName' => trim((string) ($row->school_name ?? '')),
            'governorate' => trim((string) ($row->governorate ?? '')),
            'schoolDisplay' => $schoolDisplay,
            'xpPoints' => $xp,
            'examsSolved' => (int) $row->exams_solved,
            'perfectExams' => (int) $row->perfect_exams,
            'streakDays' => $streak,
            'tier' => $tierEnum->value,
            'tierLabelAr' => $tierEnum->labelAr(),
            'badgeTitleAr' => $this->leagueRulesService->resolveRankBadgeAr($rank, true),
            'approvalStatus' => $approvalStatus,
            'championUuid' => $existingChampion?->uuid,
            'isPinned' => $existingChampion ? (bool) $existingChampion->is_pinned : false,
            'existingChampion' => $existingChampion?->toFrontendArray(),
            'suggestedChampion' => [
                'studentId' => (int) $row->student_id,
                'republicLeagueId' => $league?->id,
                'championUuid' => $existingChampion?->uuid,
                'source' => 'republic_league',
                'nameAr' => $existingChampion?->name_ar ?: $row->name,
                'nameEn' => $existingChampion?->name_en ?: $row->name,
                'gradeAr' => $existingChampion?->grade_ar ?: $canonicalGrade,
                'titleAr' => $existingChampion?->title_ar ?: ($rank === 1 ? "الأول على الجمهورية — {$canonicalGrade}" : "من أوائل الجمهورية — {$canonicalGrade}"),
                'titleEn' => $existingChampion?->title_en ?: ($rank === 1 ? "National #1 — {$gradeEn}" : "Top National Achiever — {$gradeEn}"),
                'monthAr' => $existingChampion?->month_ar ?: $monthAr,
                'monthEn' => $existingChampion?->month_en ?: 'Republic League '.Carbon::now()->format('Y'),
                'schoolAr' => $existingChampion?->school_ar ?: $schoolDisplay,
                'schoolEn' => $existingChampion?->school_en ?: $schoolDisplay,
                'scoreAr' => $existingChampion?->score_ar ?: "{$formattedXp} نقطة",
                'scoreEn' => $existingChampion?->score_en ?: "{$formattedXp} Points",
                'rankAr' => $existingChampion?->rank_ar ?: $rankAr,
                'rankEn' => $existingChampion?->rank_en ?: $rankEn,
                'streakAr' => $existingChampion?->streak_ar ?: "{$streak} يوم استريك",
                'streakEn' => $existingChampion?->streak_en ?: "{$streak}-Day Streak",
                'badgeAr' => $existingChampion?->badge_ar ?: $badgeAr,
                'badgeEn' => $existingChampion?->badge_en ?: $badgeEn,
                'image' => $existingChampion?->image_url ?: $defaultImage,
                'isPinned' => true,
                'displayOrder' => $existingChampion ? (int) $existingChampion->display_order : $rank,
            ],
        ];
    }

    /**
     * Resolve target RepublicLeague by explicit UUID, or active/latest for the requested grade.
     */
    protected function resolveTargetLeague(?string $leagueUuid, ?string $normalizedGrade): ?RepublicLeague
    {
        if ($leagueUuid !== null && trim($leagueUuid) !== '') {
            $found = RepublicLeague::query()->where('uuid', trim($leagueUuid))->first();
            if ($found) {
                return $found;
            }
        }

        return RepublicLeague::currentActive($normalizedGrade)
            ?: $this->resolveLatestLeague($normalizedGrade);
    }

    protected function resolveLatestLeague(?string $normalizedGrade): ?RepublicLeague
    {
        if ($normalizedGrade !== null) {
            $specific = RepublicLeague::query()
                ->where('grade_ar', $normalizedGrade)
                ->latest('starts_at')
                ->first();
            if ($specific) {
                return $specific;
            }
        }

        return RepublicLeague::query()
            ->whereNull('grade_ar')
            ->latest('starts_at')
            ->first()
            ?: RepublicLeague::query()->latest('starts_at')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatLeague(RepublicLeague $league): array
    {
        $durationEnum = $league->duration_type instanceof LeagueDuration
            ? $league->duration_type
            : (LeagueDuration::tryFrom((string) $league->duration_type) ?? LeagueDuration::MONTHLY);

        return [
            'id' => $league->uuid,
            'dbId' => (int) $league->id,
            'titleAr' => $league->title_ar,
            'gradeAr' => $league->grade_ar,
            'gradeLabelAr' => $league->grade_ar ?: 'جميع الصفوف (أولى إعدادي — ثالثة ثانوي)',
            'gradeLabelEn' => RepublicLeague::gradeLabelEn($league->grade_ar),
            'durationType' => $durationEnum->value,
            'durationLabelAr' => $durationEnum->labelAr(),
            'durationDays' => (int) $league->duration_days,
            'isActive' => (bool) $league->is_active,
            'startsAt' => $league->starts_at?->toIso8601String(),
            'endsAt' => $league->ends_at?->toIso8601String(),
            'daysRemaining' => $league->roundDaysRemaining(),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function formatRankLabel(int $rank, string $gradeAr): array
    {
        return match ($rank) {
            1 => ['المركز الأول جمهورياً', 'National 1st Place'],
            2 => ['المركز الثاني جمهورياً', 'National 2nd Place'],
            3 => ['المركز الثالث جمهورياً', 'National 3rd Place'],
            default => ["المركز #{$rank} — {$gradeAr}", "Rank #{$rank}"],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function suggestBadgeForRank(int $rank): array
    {
        return match ($rank) {
            1 => ['كأس التفوق الشهري', 'Monthly Excellence Cup'],
            2 => ['درع المتفوق الذهبي', 'Golden Champion Shield'],
            3 => ['وسام التميز الوطني', 'National Excellence Medal'],
            default => ['نجم دوري الجمهورية', 'Republic League Star'],
        };
    }

    private function resolveSafeAvatarForChampion(?string $avatar): string
    {
        $trimmed = trim((string) $avatar);
        if ($trimmed === '') {
            return '/characters/real-male-champ-1.png';
        }

        // Convert local full URLs pointing to /storage/... into clean relative paths accepted by SaveAdminChampionRequest
        if (preg_match('#^https?://[^/]+(/storage/[A-Za-z0-9._\-/]+)$#i', $trimmed, $m) === 1) {
            return $m[1];
        }

        if (SaveAdminChampionRequest::isSafeImageUrl($trimmed)) {
            return $trimmed;
        }

        return '/characters/real-male-champ-1.png';
    }
}
