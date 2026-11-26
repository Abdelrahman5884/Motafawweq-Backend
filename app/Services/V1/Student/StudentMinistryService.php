<?php

namespace App\Services\V1\Student;

use App\Models\MinistryCurriculum;
use App\Models\StudentMinistryExamAttempt;
use App\Models\StudentMinistryUnitProgress;
use App\Models\StudentProfile;
use App\Models\StudentXpLedger;
use App\Models\User;
use App\Services\BaseService;
use App\Support\MinistryCurriculumCatalog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class StudentMinistryService extends BaseService
{
    /**
     * List official Ministry of Education curricula tailored to the student's grade & track,
     * enriched with persisted per-unit progress and linked Ministry exam status.
     * When `$search` is provided (without an explicit grade override), searches across all published grades.
     */
    public function listCurricula(User $student, ?string $gradeFilter = null, ?string $search = null): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        [$studentGrade, $studentTrack] = $this->resolveStudentGradeAndTrack($student);
        $cleanSearch = mb_substr(trim((string) $search), 0, 120);
        $isSearching = $cleanSearch !== '';
        $explicitGradeFilter = $gradeFilter !== null && trim($gradeFilter) !== '';
        $searchAllGrades = $isSearching && ! $explicitGradeFilter;

        $showAllGrades = $searchAllGrades || ($explicitGradeFilter && strtolower(trim($gradeFilter)) === 'all');
        $selectedGrade = $showAllGrades
            ? 'all'
            : MinistryCurriculumCatalog::normalizeGrade($explicitGradeFilter ? $gradeFilter : $studentGrade);

        $query = MinistryCurriculum::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id');

        if (! $showAllGrades) {
            $query->where('grade_ar', $selectedGrade);
        }

        $curricula = $query->get()
            ->map(fn (MinistryCurriculum $m) => $m->toCatalogArray())
            ->values()
            ->all();

        if ($isSearching) {
            $curricula = array_values(array_filter($curricula, function (array $curr) use ($cleanSearch) {
                $unitFields = [];
                foreach (($curr['units'] ?? []) as $unit) {
                    $unitFields[] = $unit['titleAr'] ?? '';
                    $unitFields[] = $unit['titleEn'] ?? '';
                    foreach (($unit['topics'] ?? []) as $topic) {
                        $unitFields[] = is_string($topic) ? $topic : '';
                    }
                }

                return MinistryCurriculumCatalog::matchesSearchQuery($cleanSearch, array_merge([
                    $curr['code'] ?? '',
                    $curr['titleAr'] ?? '',
                    $curr['titleEn'] ?? '',
                    $curr['subjectAr'] ?? '',
                    $curr['subjectEn'] ?? '',
                    $curr['gradeAr'] ?? '',
                    $curr['gradeSubtitleAr'] ?? '',
                    $curr['gradeSubtitleEn'] ?? '',
                    $curr['descriptionAr'] ?? '',
                    MinistryCurriculumCatalog::gradeSearchAliases($curr['gradeAr'] ?? ''),
                ], $unitFields));
            }));
        }

        // Prioritize student's own grade first (in cross-grade search), then matching track, then catalog order
        $indexedCurricula = [];
        foreach ($curricula as $idx => $curr) {
            $gradeRank = ($curr['gradeAr'] ?? '') === $studentGrade ? 0 : 1;
            $trackRank = $this->matchesTrack($curr['tracks'] ?? [], $studentTrack) ? 0 : 1;
            $indexedCurricula[] = ['_sortKey' => [$gradeRank, $trackRank, $idx], 'curr' => $curr];
        }
        usort($indexedCurricula, fn (array $a, array $b) => $a['_sortKey'] <=> $b['_sortKey']);
        $curricula = array_map(fn (array $row) => $row['curr'], $indexedCurricula);

        $codes = array_column($curricula, 'code');
        $examCodes = array_values(array_filter(array_column($curricula, 'examCode')));

        // Bulk-load unit completion progress (zero N+1)
        $completedUnitsByCode = [];
        if (! empty($codes)) {
            $rows = StudentMinistryUnitProgress::query()
                ->where('student_id', $student->id)
                ->whereIn('curriculum_code', $codes)
                ->where('is_completed', true)
                ->get(['curriculum_code', 'unit_index']);

            foreach ($rows as $row) {
                $completedUnitsByCode[$row->curriculum_code][] = (int) $row->unit_index;
            }
        }

        // Bulk-load latest/best Ministry exam attempts linked to each curriculum
        $attemptsByExamCode = ! empty($examCodes)
            ? StudentMinistryExamAttempt::query()
                ->where('student_id', $student->id)
                ->whereIn('exam_code', $examCodes)
                ->where('status', 'graded')
                ->orderByDesc('id')
                ->get()
                ->groupBy('exam_code')
            : collect();

        $examsIndex = [];
        foreach (MinistryCurriculumCatalog::exams() as $ex) {
            $examsIndex[$ex['code']] = $ex;
        }

        $items = array_map(function (array $curr) use ($completedUnitsByCode, $attemptsByExamCode, $examsIndex, $studentTrack, $studentGrade) {
            $units = $curr['units'] ?? [];
            $unitsCount = count($units);
            $chaptersCount = array_sum(array_map(fn (array $u) => (int) ($u['chaptersCount'] ?? 1), $units));
            $completedIndices = array_values(array_unique($completedUnitsByCode[$curr['code']] ?? []));
            sort($completedIndices);

            $completedUnitsCount = count(array_filter(
                $completedIndices,
                fn (int $idx) => $idx >= 0 && $idx < $unitsCount
            ));
            $progressPercent = $unitsCount > 0
                ? min(100, (int) round(($completedUnitsCount / $unitsCount) * 100))
                : 0;

            // Active unit = first incomplete unit (or last unit if 100% done)
            $activeUnit = $units[0] ?? null;
            foreach ($units as $idx => $unit) {
                if (! in_array($idx, $completedIndices, true)) {
                    $activeUnit = $unit;
                    break;
                }
            }
            if ($completedUnitsCount >= $unitsCount && $unitsCount > 0) {
                $activeUnit = $units[$unitsCount - 1];
            }

            $flatTopics = [];
            $formattedUnits = [];
            $lessonsCount = 0;
            $videosCount = 0;
            $resourcesCount = 0;
            foreach ($units as $idx => $unit) {
                $unitTopics = is_array($unit['topics'] ?? null) ? array_values($unit['topics']) : [];
                $unitVideos = is_array($unit['videos'] ?? null) ? array_values($unit['videos']) : [];
                $unitLessons = is_array($unit['lessons'] ?? null) ? array_values($unit['lessons']) : $unitVideos;
                $unitResources = is_array($unit['resources'] ?? null) ? array_values($unit['resources']) : [];
                $lessonsCount += count($unitLessons);
                $videosCount += count($unitVideos);
                $resourcesCount += count($unitResources);
                foreach ($unitLessons as $les) {
                    $resourcesCount += count((array) ($les['attachments'] ?? []));
                }
                foreach ($unitTopics as $t) {
                    $flatTopics[] = $t;
                }
                $formattedUnits[] = [
                    'index' => $idx,
                    'titleAr' => $unit['titleAr'] ?? '',
                    'titleEn' => $unit['titleEn'] ?? ($unit['titleAr'] ?? ''),
                    'summaryAr' => $unit['summaryAr'] ?? '',
                    'chaptersCount' => (int) ($unit['chaptersCount'] ?? max(1, count($unitLessons) ?: count($unitTopics))),
                    'topics' => $unitTopics,
                    'lessons' => $unitLessons,
                    'videos' => $unitVideos,
                    'resources' => $unitResources,
                    'isCompleted' => in_array($idx, $completedIndices, true),
                ];
            }

            $examCode = $curr['examCode'] ?? null;
            $examMeta = $examCode ? ($examsIndex[$examCode] ?? null) : null;
            $examAttempts = $examCode ? ($attemptsByExamCode->get($examCode) ?? collect()) : collect();
            $bestExamPct = $examAttempts->isNotEmpty() ? (float) $examAttempts->max('percentage') : null;

            return [
                'id' => $curr['code'],
                'code' => $curr['code'],
                'titleAr' => $curr['titleAr'],
                'titleEn' => $curr['titleEn'],
                'subjectAr' => $curr['subjectAr'],
                'subjectEn' => $curr['subjectEn'],
                'gradeAr' => $curr['gradeAr'],
                'isSameGrade' => ($curr['gradeAr'] ?? '') === $studentGrade,
                'gradeSubtitleAr' => $curr['gradeSubtitleAr'] ?? $curr['gradeAr'],
                'gradeSubtitleEn' => $curr['gradeSubtitleEn'] ?? $curr['gradeAr'],
                'stage' => $curr['stage'],
                'term' => $curr['term'] ?? 'full_year',
                'tracks' => $curr['tracks'] ?? [],
                'descriptionAr' => $curr['descriptionAr'] ?? '',
                'descriptionEn' => $curr['descriptionEn'] ?? '',
                'isRecommendedForTrack' => $this->matchesTrack($curr['tracks'] ?? [], $studentTrack),
                'sourceAr' => MinistryCurriculumCatalog::SOURCE_AR,
                'sourceEn' => MinistryCurriculumCatalog::SOURCE_EN,
                'unitsCount' => $unitsCount,
                'chaptersCount' => $chaptersCount,
                'lessonsCount' => $lessonsCount,
                'videosCount' => $videosCount,
                'resourcesCount' => $resourcesCount,
                'completedUnitsCount' => $completedUnitsCount,
                'completedUnitIndices' => $completedIndices,
                'progressPercent' => $progressPercent,
                'activeUnitAr' => $activeUnit['titleAr'] ?? '',
                'activeUnitEn' => $activeUnit['titleEn'] ?? ($activeUnit['titleAr'] ?? ''),
                'units' => $formattedUnits,
                'topics' => array_slice($flatTopics, 0, 8),
                'textbookUrl' => $curr['textbookUrl'] ?? MinistryCurriculumCatalog::OFFICIAL_PORTAL_URL,
                'hasTextbookPdf' => true,
                'hasQuestionBank' => $examMeta !== null,
                'examCode' => $examCode,
                'examQuestionsCount' => $examMeta ? count($examMeta['questions'] ?? []) : 0,
                'examAttemptsCount' => $examAttempts->count(),
                'examBestPercentage' => $bestExamPct !== null ? round($bestExamPct, 1) : null,
            ];
        }, $curricula);

        return [
            'studentGradeAr' => $studentGrade,
            'studentTrackAr' => $studentTrack,
            'selectedGradeAr' => $selectedGrade,
            'isSearchingAllGrades' => $searchAllGrades,
            'availableGrades' => MinistryCurriculumCatalog::availableGrades(),
            'items' => $items,
        ];
    }

    /**
     * Toggle completion state of a specific unit inside an official Ministry curriculum.
     */
    public function toggleUnitCompletion(User $student, string $curriculumCode, int $unitIndex): array
    {
        MinistryCurriculum::ensureCatalogSeeded();

        $curriculumModel = MinistryCurriculum::query()
            ->where('code', $curriculumCode)
            ->where('is_published', true)
            ->first();

        $curriculum = $curriculumModel?->toCatalogArray();
        if (! $curriculum) {
            throw (new ModelNotFoundException)->setModel(StudentMinistryUnitProgress::class, [$curriculumCode]);
        }

        $units = $curriculum['units'] ?? [];
        $unitsCount = count($units);
        if ($unitIndex < 0 || $unitIndex >= $unitsCount) {
            throw ValidationException::withMessages([
                'unit_index' => ['رقم الوحدة المطلوبة غير صالح لهذا المقرر.'],
            ]);
        }

        return $this->transaction(function () use ($student, $curriculumCode, $unitIndex, $units, $unitsCount) {
            $existing = StudentMinistryUnitProgress::query()
                ->where('student_id', $student->id)
                ->where('curriculum_code', $curriculumCode)
                ->where('unit_index', $unitIndex)
                ->first();

            $nextCompleted = ! ($existing?->is_completed ?? false);

            if ($existing) {
                $existing->update([
                    'is_completed' => $nextCompleted,
                    'completed_at' => $nextCompleted ? now() : null,
                ]);
            } else {
                StudentMinistryUnitProgress::create([
                    'student_id' => $student->id,
                    'curriculum_code' => $curriculumCode,
                    'unit_index' => $unitIndex,
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
            }

            $completedIndices = StudentMinistryUnitProgress::query()
                ->where('student_id', $student->id)
                ->where('curriculum_code', $curriculumCode)
                ->where('is_completed', true)
                ->pluck('unit_index')
                ->map(fn ($v) => (int) $v)
                ->sort()
                ->values()
                ->all();

            $completedUnitsCount = count($completedIndices);
            $progressPercent = $unitsCount > 0
                ? min(100, (int) round(($completedUnitsCount / $unitsCount) * 100))
                : 0;

            $activeUnit = $units[0] ?? null;
            foreach ($units as $idx => $unit) {
                if (! in_array($idx, $completedIndices, true)) {
                    $activeUnit = $unit;
                    break;
                }
            }
            if ($completedUnitsCount >= $unitsCount && $unitsCount > 0) {
                $activeUnit = $units[$unitsCount - 1];
            }

            return [
                'curriculumCode' => $curriculumCode,
                'unitIndex' => $unitIndex,
                'isCompleted' => $nextCompleted,
                'completedUnitIndices' => $completedIndices,
                'completedUnitsCount' => $completedUnitsCount,
                'unitsCount' => $unitsCount,
                'progressPercent' => $progressPercent,
                'activeUnitAr' => $activeUnit['titleAr'] ?? '',
                'activeUnitEn' => $activeUnit['titleEn'] ?? ($activeUnit['titleAr'] ?? ''),
            ];
        });
    }

    /**
     * List official Ministry of Education guided practice exams with live student attempt status.
     * By default strictly scoped to the student's current grade; when `$search` is provided
     * (without an explicit grade override), searches across all grades.
     */
    public function listExams(User $student, ?string $gradeFilter = null, ?string $search = null): array
    {
        [$studentGrade, $studentTrack] = $this->resolveStudentGradeAndTrack($student);
        $cleanSearch = mb_substr(trim((string) $search), 0, 120);
        $isSearching = $cleanSearch !== '';
        $explicitGradeFilter = $gradeFilter !== null && trim($gradeFilter) !== '';
        $searchAllGrades = $isSearching && ! $explicitGradeFilter;

        $showAllGrades = $searchAllGrades || ($explicitGradeFilter && strtolower(trim($gradeFilter)) === 'all');
        $selectedGrade = $showAllGrades
            ? 'all'
            : MinistryCurriculumCatalog::normalizeGrade($explicitGradeFilter ? $gradeFilter : $studentGrade);

        $exams = MinistryCurriculumCatalog::exams();
        if (! $showAllGrades) {
            $exams = array_values(array_filter(
                $exams,
                fn (array $ex) => $ex['gradeAr'] === $selectedGrade
            ));
        }

        if ($isSearching) {
            $exams = array_values(array_filter($exams, fn (array $ex) => MinistryCurriculumCatalog::matchesSearchQuery(
                $cleanSearch,
                [
                    $ex['code'] ?? '',
                    $ex['curriculumCode'] ?? '',
                    $ex['titleAr'] ?? '',
                    $ex['titleEn'] ?? '',
                    $ex['subjectAr'] ?? '',
                    $ex['subjectEn'] ?? '',
                    $ex['gradeAr'] ?? '',
                    MinistryCurriculumCatalog::gradeSearchAliases($ex['gradeAr'] ?? ''),
                    MinistryCurriculumCatalog::SOURCE_AR,
                    MinistryCurriculumCatalog::SOURCE_EN,
                ]
            )));
        }

        $indexedExams = [];
        foreach ($exams as $idx => $ex) {
            $gradeRank = ($ex['gradeAr'] ?? '') === $studentGrade ? 0 : 1;
            $trackRank = $this->matchesTrack($ex['tracks'] ?? [], $studentTrack) ? 0 : 1;
            $indexedExams[] = ['_sortKey' => [$gradeRank, $trackRank, $idx], 'exam' => $ex];
        }
        usort($indexedExams, fn (array $a, array $b) => $a['_sortKey'] <=> $b['_sortKey']);
        $exams = array_map(fn (array $row) => $row['exam'], $indexedExams);

        $examCodes = array_column($exams, 'code');
        $attemptsByCode = ! empty($examCodes)
            ? StudentMinistryExamAttempt::query()
                ->where('student_id', $student->id)
                ->whereIn('exam_code', $examCodes)
                ->orderByDesc('id')
                ->get()
                ->groupBy('exam_code')
            : collect();

        $now = Carbon::now();

        $items = array_map(function (array $exam) use ($attemptsByCode, $now, $studentGrade) {
            $questions = $exam['questions'] ?? [];
            $fullMark = $this->computeFullMark($questions);
            $passScore = (int) ceil($fullMark * (($exam['passPercentage'] ?? 50) / 100));

            $attempts = $attemptsByCode->get($exam['code']) ?? collect();
            /** @var StudentMinistryExamAttempt|null $activeInProgress */
            $activeInProgress = $attempts->first(function (StudentMinistryExamAttempt $a) use ($now) {
                return $a->status === 'in_progress'
                    && (! $a->expires_at || $now->lessThanOrEqualTo($a->expires_at->copy()->addSeconds(60)));
            });

            $gradedAttempts = $attempts->where('status', 'graded');
            /** @var StudentMinistryExamAttempt|null $latestGraded */
            $latestGraded = $gradedAttempts->first();

            $submissionStatus = 'not_started';
            $remainingSeconds = null;
            if ($activeInProgress) {
                $submissionStatus = 'in_progress';
                $remainingSeconds = $activeInProgress->expires_at
                    ? max(0, (int) $now->diffInSeconds($activeInProgress->expires_at, false))
                    : ((int) $exam['durationMinutes'] * 60);
            } elseif ($latestGraded) {
                $submissionStatus = 'graded';
            }

            $bestPercentage = $gradedAttempts->isNotEmpty()
                ? round((float) $gradedAttempts->max('percentage'), 1)
                : null;

            return [
                'id' => $exam['code'],
                'uuid' => $exam['code'],
                'code' => $exam['code'],
                'curriculumCode' => $exam['curriculumCode'] ?? null,
                'isMinistry' => true,
                'sourceAr' => MinistryCurriculumCatalog::SOURCE_AR,
                'sourceEn' => MinistryCurriculumCatalog::SOURCE_EN,
                'titleAr' => $exam['titleAr'],
                'titleEn' => $exam['titleEn'] ?? $exam['titleAr'],
                'courseTitleAr' => $exam['subjectAr'],
                'subjectAr' => $exam['subjectAr'],
                'subjectEn' => $exam['subjectEn'] ?? $exam['subjectAr'],
                'gradeAr' => $exam['gradeAr'],
                'isSameGrade' => ($exam['gradeAr'] ?? '') === $studentGrade,
                'teacherName' => MinistryCurriculumCatalog::SOURCE_AR,
                'durationMinutes' => (int) $exam['durationMinutes'],
                'fullMark' => $fullMark,
                'passScore' => $passScore,
                'questionsCount' => count($questions),
                'status' => 'active',
                'isUpcoming' => false,
                'isBubbleSheet' => (bool) ($exam['isBubbleSheet'] ?? true),
                'showResults' => true,
                'startsAt' => null,
                'dueDate' => null,
                'submissionStatus' => $submissionStatus,
                'remainingSeconds' => $remainingSeconds,
                'submittedAt' => $latestGraded?->submitted_at?->toIso8601String(),
                'score' => $latestGraded ? (float) $latestGraded->score : null,
                'percentage' => $latestGraded ? (float) $latestGraded->percentage : null,
                'isPassed' => $latestGraded ? (bool) $latestGraded->is_passed : null,
                'isFullMark' => $latestGraded ? (bool) $latestGraded->is_full_mark : null,
                'attemptsCount' => $gradedAttempts->count(),
                'bestPercentage' => $bestPercentage,
                'canRetake' => true,
            ];
        }, $exams);

        return [
            'studentGradeAr' => $studentGrade,
            'studentTrackAr' => $studentTrack,
            'selectedGradeAr' => $selectedGrade,
            'isSearchingAllGrades' => $searchAllGrades,
            'availableGrades' => MinistryCurriculumCatalog::availableGrades(),
            'items' => $items,
        ];
    }

    /**
     * Start or resume a server-timed Ministry guided practice exam session (WITHOUT leaking answers).
     */
    public function startExam(User $student, string $examCode, bool $retake = false): array
    {
        $exam = MinistryCurriculumCatalog::findExam($examCode);
        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(StudentMinistryExamAttempt::class, [$examCode]);
        }

        $questions = $exam['questions'] ?? [];
        $fullMark = $this->computeFullMark($questions);
        $passScore = (int) ceil($fullMark * (($exam['passPercentage'] ?? 50) / 100));
        $duration = max(5, (int) ($exam['durationMinutes'] ?? 20));

        $attempt = $this->transaction(function () use ($student, $exam, $fullMark, $duration, $retake) {
            $now = Carbon::now();

            /** @var StudentMinistryExamAttempt|null $existing */
            $existing = StudentMinistryExamAttempt::query()
                ->where('student_id', $student->id)
                ->where('exam_code', $exam['code'])
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                $isExpired = $existing->expires_at && $now->greaterThan($existing->expires_at->copy()->addSeconds(60));
                if (! $isExpired && ! $retake) {
                    return $existing;
                }

                // Close stale/expired in-progress attempt cleanly before starting a fresh one
                $existing->update([
                    'submitted_at' => $existing->expires_at ?: $now,
                    'score' => 0,
                    'percentage' => 0,
                    'is_passed' => false,
                    'is_full_mark' => false,
                    'unanswered_count' => count($exam['questions'] ?? []),
                    'status' => 'graded',
                ]);
            }

            return StudentMinistryExamAttempt::create([
                'student_id' => $student->id,
                'exam_code' => $exam['code'],
                'subject_ar' => $exam['subjectAr'],
                'grade_ar' => $exam['gradeAr'],
                'title_ar' => $exam['titleAr'],
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes($duration),
                'full_mark' => $fullMark,
                'status' => 'in_progress',
            ]);
        });

        // Strip correct answers and explanations strictly before returning to client
        $sanitizedQuestions = array_map(fn (array $q) => [
            'id' => $q['id'],
            'uuid' => $q['id'],
            'questionNumber' => (int) $q['questionNumber'],
            'type' => (string) ($q['type'] ?? 'mcq'),
            'points' => (int) ($q['points'] ?? 2),
            'difficulty' => (string) ($q['difficulty'] ?? 'medium'),
            'questionAr' => $q['questionAr'],
            'questionEn' => $q['questionEn'] ?? $q['questionAr'],
            'optionsAr' => isset($q['optionsAr']) && is_array($q['optionsAr']) ? array_values($q['optionsAr']) : [],
        ], $questions);

        $remainingSeconds = $attempt->expires_at
            ? max(0, (int) Carbon::now()->diffInSeconds($attempt->expires_at, false))
            : ($duration * 60);

        return [
            'attemptId' => $attempt->uuid,
            'examId' => $exam['code'],
            'isMinistry' => true,
            'titleAr' => $exam['titleAr'],
            'courseTitleAr' => $exam['subjectAr'],
            'durationMinutes' => $duration,
            'fullMark' => $fullMark,
            'passScore' => $passScore,
            'showResults' => true,
            'startedAt' => $attempt->started_at?->toIso8601String(),
            'expiresAt' => $attempt->expires_at?->toIso8601String(),
            'remainingSeconds' => $remainingSeconds,
            'questions' => $sanitizedQuestions,
        ];
    }

    /**
     * Auto-grade a Ministry guided practice exam submission server-side and return full review.
     */
    public function submitExam(User $student, string $examCode, array $payload): array
    {
        $exam = MinistryCurriculumCatalog::findExam($examCode);
        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(StudentMinistryExamAttempt::class, [$examCode]);
        }

        $questions = $exam['questions'] ?? [];
        $fullMark = $this->computeFullMark($questions);
        $passScore = (int) ceil($fullMark * (($exam['passPercentage'] ?? 50) / 100));

        $submittedAnswersMap = [];
        foreach (($payload['answers'] ?? []) as $ans) {
            $qId = (string) ($ans['questionUuid'] ?? $ans['question_uuid'] ?? $ans['questionId'] ?? '');
            if ($qId !== '') {
                $submittedAnswersMap[$qId] = $ans;
            }
        }

        $tabSwitches = max(0, (int) ($payload['tabSwitchesCount'] ?? $payload['tab_switches_count'] ?? 0));

        $score = 0.0;
        $correctCount = 0;
        $wrongCount = 0;
        $unansweredCount = 0;
        $storedAnswers = [];

        foreach ($questions as $q) {
            $qId = (string) $q['id'];
            $type = (string) ($q['type'] ?? 'mcq');
            $points = (int) ($q['points'] ?? 2);
            $userAns = $submittedAnswersMap[$qId] ?? [];

            $selectedIndex = array_key_exists('selectedIndex', $userAns) && $userAns['selectedIndex'] !== null
                ? (int) $userAns['selectedIndex']
                : (array_key_exists('selected_index', $userAns) && $userAns['selected_index'] !== null
                    ? (int) $userAns['selected_index']
                    : null);

            $selectedBool = array_key_exists('selectedBool', $userAns) && $userAns['selectedBool'] !== null
                ? (bool) $userAns['selectedBool']
                : (array_key_exists('selected_bool', $userAns) && $userAns['selected_bool'] !== null
                    ? (bool) $userAns['selected_bool']
                    : null);

            $isCorrect = false;
            $hasAnswer = false;

            if ($type === 'true_false') {
                $hasAnswer = $selectedBool !== null;
                $isCorrect = $hasAnswer && $selectedBool === (bool) ($q['correctBool'] ?? false);
            } else {
                $hasAnswer = $selectedIndex !== null;
                $isCorrect = $hasAnswer && $selectedIndex === (int) ($q['correctIndex'] ?? -1);
            }

            $pointsAwarded = $isCorrect ? (float) $points : 0.0;
            $score += $pointsAwarded;

            if ($isCorrect) {
                $correctCount++;
            } elseif ($hasAnswer) {
                $wrongCount++;
            } else {
                $unansweredCount++;
            }

            $storedAnswers[] = [
                'questionId' => $qId,
                'selectedIndex' => $selectedIndex,
                'selectedBool' => $selectedBool,
                'isCorrect' => $isCorrect,
                'pointsAwarded' => $pointsAwarded,
            ];
        }

        $percentage = $fullMark > 0 ? round(($score / $fullMark) * 100, 2) : 0.0;
        $isPassed = $score >= $passScore;
        $isFullMark = $fullMark > 0 && $score >= $fullMark;

        $attempt = $this->transaction(function () use (
            $student,
            $exam,
            $fullMark,
            $score,
            $percentage,
            $isPassed,
            $isFullMark,
            $correctCount,
            $wrongCount,
            $unansweredCount,
            $tabSwitches,
            $storedAnswers
        ) {
            $now = Carbon::now();

            /** @var StudentMinistryExamAttempt|null $existing */
            $existing = StudentMinistryExamAttempt::query()
                ->where('student_id', $student->id)
                ->where('exam_code', $exam['code'])
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $sourceId = abs(crc32($exam['code'])) & 0x7FFFFFFF;
            $alreadyAwarded = StudentXpLedger::query()
                ->where('student_id', $student->id)
                ->where('source_type', 'ministry_exam_completed')
                ->where('source_id', $sourceId)
                ->exists();

            $xpToAward = 0;
            if (! $alreadyAwarded) {
                $xpToAward = $isFullMark ? 25 : ($isPassed ? 15 : 5);
                StudentXpLedger::awardOnce(
                    $student->id,
                    'ministry_exam_completed',
                    $sourceId,
                    $xpToAward,
                    "إتمام اختبار وزارة التربية والتعليم: {$exam['subjectAr']}"
                );
            }

            $attributes = [
                'submitted_at' => $now,
                'answers_json' => $storedAnswers,
                'score' => $score,
                'full_mark' => $fullMark,
                'percentage' => $percentage,
                'is_passed' => $isPassed,
                'is_full_mark' => $isFullMark,
                'correct_count' => $correctCount,
                'wrong_count' => $wrongCount,
                'unanswered_count' => $unansweredCount,
                'tab_switches_count' => $tabSwitches,
                'xp_awarded' => $xpToAward,
                'status' => 'graded',
            ];

            if ($existing) {
                $existing->update($attributes);

                return $existing->fresh();
            }

            return StudentMinistryExamAttempt::create(array_merge([
                'student_id' => $student->id,
                'exam_code' => $exam['code'],
                'subject_ar' => $exam['subjectAr'],
                'grade_ar' => $exam['gradeAr'],
                'title_ar' => $exam['titleAr'],
                'started_at' => $now,
                'expires_at' => $now,
            ], $attributes));
        });

        return $this->formatAttemptResult($attempt, $exam, $passScore);
    }

    /**
     * Retrieve the student's latest graded result & review breakdown for a Ministry exam.
     */
    public function getExamResult(User $student, string $examCode): array
    {
        $exam = MinistryCurriculumCatalog::findExam($examCode);
        if (! $exam) {
            throw (new ModelNotFoundException)->setModel(StudentMinistryExamAttempt::class, [$examCode]);
        }

        /** @var StudentMinistryExamAttempt|null $attempt */
        $attempt = StudentMinistryExamAttempt::query()
            ->where('student_id', $student->id)
            ->where('exam_code', $examCode)
            ->where('status', 'graded')
            ->latest('id')
            ->first();

        if (! $attempt) {
            throw (new ModelNotFoundException)->setModel(StudentMinistryExamAttempt::class, [$examCode]);
        }

        $fullMark = $this->computeFullMark($exam['questions'] ?? []);
        $passScore = (int) ceil($fullMark * (($exam['passPercentage'] ?? 50) / 100));

        return $this->formatAttemptResult($attempt, $exam, $passScore);
    }

    /**
     * Format a graded Ministry exam attempt into the exact structure expected by ExamResults.jsx.
     */
    protected function formatAttemptResult(StudentMinistryExamAttempt $attempt, array $exam, int $passScore): array
    {
        $questions = $exam['questions'] ?? [];
        $storedMap = [];
        foreach ((array) ($attempt->answers_json ?? []) as $row) {
            $qId = (string) ($row['questionId'] ?? '');
            if ($qId !== '') {
                $storedMap[$qId] = $row;
            }
        }

        $review = array_map(function (array $q) use ($storedMap) {
            $qId = (string) $q['id'];
            $ans = $storedMap[$qId] ?? [];
            $type = (string) ($q['type'] ?? 'mcq');

            return [
                'id' => $qId,
                'questionId' => $qId,
                'questionNumber' => (int) $q['questionNumber'],
                'type' => $type,
                'points' => (int) ($q['points'] ?? 2),
                'pointsAwarded' => (float) ($ans['pointsAwarded'] ?? 0),
                'questionAr' => $q['questionAr'],
                'optionsAr' => isset($q['optionsAr']) && is_array($q['optionsAr']) ? array_values($q['optionsAr']) : [],
                'selectedIndex' => array_key_exists('selectedIndex', $ans) && $ans['selectedIndex'] !== null ? (int) $ans['selectedIndex'] : null,
                'selectedBool' => array_key_exists('selectedBool', $ans) && $ans['selectedBool'] !== null ? (bool) $ans['selectedBool'] : null,
                'essayAnswerText' => null,
                'correctIndex' => array_key_exists('correctIndex', $q) ? (int) $q['correctIndex'] : null,
                'correctBool' => array_key_exists('correctBool', $q) ? (bool) $q['correctBool'] : null,
                'modelAnswerAr' => null,
                'explanationAr' => $q['explanationAr'] ?? null,
                'isCorrect' => (bool) ($ans['isCorrect'] ?? false),
                'teacherComment' => null,
            ];
        }, $questions);

        return [
            'id' => $attempt->uuid,
            'examId' => $exam['code'],
            'isMinistry' => true,
            'examTitleAr' => $exam['titleAr'],
            'courseTitleAr' => $exam['subjectAr'],
            'status' => 'graded',
            'statusLabelAr' => 'تم التصحيح',
            'isPendingManualGrading' => false,
            'score' => (float) $attempt->score,
            'fullMark' => (int) $attempt->full_mark,
            'passScore' => $passScore,
            'percentage' => (float) ($attempt->percentage ?? 0),
            'isPassed' => (bool) $attempt->is_passed,
            'isFullMark' => (bool) $attempt->is_full_mark,
            'correctCount' => (int) $attempt->correct_count,
            'wrongCount' => (int) $attempt->wrong_count,
            'unansweredCount' => (int) $attempt->unanswered_count,
            'essayCount' => 0,
            'totalQuestions' => count($questions),
            'xpAwarded' => (int) $attempt->xp_awarded,
            'teacherFeedback' => null,
            'startedAt' => $attempt->started_at?->toIso8601String(),
            'submittedAt' => $attempt->submitted_at?->toIso8601String(),
            'gradedAt' => $attempt->submitted_at?->toIso8601String(),
            'showResults' => true,
            'review' => $review,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveStudentGradeAndTrack(User $student): array
    {
        /** @var StudentProfile|null $profile */
        $profile = StudentProfile::where('student_id', $student->id)->first(['grade_ar', 'track_ar']);
        $grade = MinistryCurriculumCatalog::normalizeGrade($profile?->grade_ar);
        $track = trim((string) ($profile?->track_ar ?? 'علمي علوم'));

        return [$grade, $track];
    }

    /**
     * @param  list<string>  $itemTracks
     */
    protected function matchesTrack(array $itemTracks, string $studentTrack): bool
    {
        if (empty($itemTracks) || $studentTrack === '') {
            return true;
        }

        return in_array($studentTrack, $itemTracks, true);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    protected function computeFullMark(array $questions): int
    {
        $sum = array_sum(array_map(fn (array $q) => (int) ($q['points'] ?? 2), $questions));

        return max(1, $sum);
    }
}
