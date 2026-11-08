<?php

namespace App\Services\V1\Teacher;

use App\Enums\ExamQuestionType;
use App\Enums\ExamStatus;
use App\Enums\TeacherPlanTier;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherExamService extends BaseService
{
    public function __construct(
        protected ExamRepositoryInterface $examRepository
    ) {}

    /**
     * List all exams owned by the teacher with search & status filters.
     */
    public function listExams(User $teacher, array $filters = []): Collection
    {
        return $this->examRepository->getForTeacher($teacher->id, $filters);
    }

    /**
     * Retrieve single exam details with all questions and course mapping.
     */
    public function getExam(User $teacher, string $uuid): Exam
    {
        return $this->examRepository->findByUuidForTeacher($uuid, $teacher);
    }

    /**
     * Create a new exam with its questions matrix inside a single atomic transaction.
     */
    public function createExam(User $teacher, array $data): Exam
    {
        $rawQuestions = $data['questions'] ?? [];
        if (!is_array($rawQuestions) || count($rawQuestions) === 0) {
            throw ValidationException::withMessages([
                'questions' => [__('messages.exam_questions_required')],
            ]);
        }

        // Check Free Tier Question Bank Quota if applicable
        $this->assertQuestionBankQuota($teacher, count($rawQuestions));

        // Resolve Course if provided
        $courseId = null;
        $courseTitle = trim((string) ($data['course_title_ar'] ?? $data['courseTitleAr'] ?? ''));
        $courseIdentifier = $data['course_id'] ?? $data['courseId'] ?? null;
        if ($courseIdentifier) {
            $course = Course::where('uuid', $courseIdentifier)
                ->orWhere('id', is_numeric($courseIdentifier) ? (int) $courseIdentifier : 0)
                ->first();

            if ($course) {
                $course->assertOwnedBy($teacher);
                $courseId = $course->id;
                $courseTitle = $courseTitle ?: $course->title_ar;
            }
        }

        return $this->transaction(function () use ($teacher, $data, $rawQuestions, $courseId, $courseTitle) {
            $titleAr = trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'] ?? 'امتحان جديد')));
            $titleEn = trim(strip_tags((string) ($data['title_en'] ?? $data['titleEn'] ?? $titleAr)));
            $durationMinutes = max(5, (int) ($data['duration_minutes'] ?? $data['durationMinutes'] ?? 60));
            $passScore = max(0, (int) ($data['pass_score'] ?? $data['passScore'] ?? 25));
            $dueDate = !empty($data['due_date']) || !empty($data['dueDate'])
                ? Carbon::parse($data['due_date'] ?? $data['dueDate'])->toDateString()
                : null;

            $isBubbleSheet = (bool) ($data['is_bubble_sheet'] ?? $data['isBubbleSheet'] ?? false);
            $showResults = isset($data['show_results']) || isset($data['showResults'])
                ? (bool) ($data['show_results'] ?? $data['showResults'])
                : true;

            $status = strtolower(trim((string) ($data['status'] ?? 'active')));
            $statusEnum = ExamStatus::tryFrom($status) ?? ExamStatus::ACTIVE;

            /** @var Exam $exam */
            $exam = Exam::create([
                'teacher_id'       => $teacher->id,
                'course_id'        => $courseId,
                'course_title_ar'  => $courseTitle ?: 'المقرر الدراسي',
                'title_ar'         => $titleAr,
                'title_en'         => $titleEn,
                'stage'            => trim((string) ($data['stage'] ?? 'sec')),
                'grade_ar'         => trim((string) ($data['grade_ar'] ?? $data['gradeAr'] ?? 'الصف الثالث الثانوي')),
                'duration_minutes' => $durationMinutes,
                'pass_score'       => $passScore,
                'status'           => $statusEnum,
                'show_results'     => $showResults,
                'is_bubble_sheet'  => $isBubbleSheet,
                'due_date'         => $dueDate,
            ]);

            $this->syncExamQuestions($exam, $rawQuestions);
            $exam->recalculateMetrics();

            $this->logSecurityEvent('teacher.exam.created', [
                'exam_uuid'       => $exam->uuid,
                'questions_count' => $exam->questions_count,
                'full_mark'       => $exam->full_mark,
            ], 'info', $exam);

            return $exam->load(['questions', 'course']);
        });
    }

    /**
     * Update exam settings and optionally synchronize questions.
     */
    public function updateExam(User $teacher, string $uuid, array $data): Exam
    {
        // Enforce ownership BEFORE opening transaction
        $exam = $this->examRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $exam, $data) {
            $fillable = [];

            if (isset($data['title_ar']) || isset($data['titleAr'])) {
                $fillable['title_ar'] = trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'])));
            }
            if (isset($data['title_en']) || isset($data['titleEn'])) {
                $fillable['title_en'] = trim(strip_tags((string) ($data['title_en'] ?? $data['titleEn'])));
            }
            if (isset($data['course_title_ar']) || isset($data['courseTitleAr'])) {
                $fillable['course_title_ar'] = trim(strip_tags((string) ($data['course_title_ar'] ?? $data['courseTitleAr'])));
            }
            if (isset($data['duration_minutes']) || isset($data['durationMinutes'])) {
                $fillable['duration_minutes'] = max(5, (int) ($data['duration_minutes'] ?? $data['durationMinutes']));
            }
            if (isset($data['pass_score']) || isset($data['passScore'])) {
                $fillable['pass_score'] = max(0, (int) ($data['pass_score'] ?? $data['passScore']));
            }
            if (isset($data['full_mark']) || isset($data['fullMark'])) {
                $fillable['full_mark'] = max(1, (int) ($data['full_mark'] ?? $data['fullMark']));
            }
            if (isset($data['due_date']) || isset($data['dueDate'])) {
                $rawDue = $data['due_date'] ?? $data['dueDate'];
                $fillable['due_date'] = $rawDue ? Carbon::parse($rawDue)->toDateString() : null;
            }
            if (isset($data['is_bubble_sheet']) || isset($data['isBubbleSheet'])) {
                $fillable['is_bubble_sheet'] = (bool) ($data['is_bubble_sheet'] ?? $data['isBubbleSheet']);
            }
            if (isset($data['show_results']) || isset($data['showResults'])) {
                $fillable['show_results'] = (bool) ($data['show_results'] ?? $data['showResults']);
            }
            if (isset($data['status'])) {
                $st = strtolower(trim((string) $data['status']));
                $fillable['status'] = ExamStatus::tryFrom($st)?->value ?? 'active';
            }

            if (!empty($fillable)) {
                $exam->update($fillable);
            }

            // If questions array is passed, re-sync questions
            if (isset($data['questions']) && is_array($data['questions']) && count($data['questions']) > 0) {
                $this->assertQuestionBankQuota($teacher, count($data['questions']));
                $this->syncExamQuestions($exam, $data['questions']);
                $exam->recalculateMetrics();
            }

            $this->logSecurityEvent('teacher.exam.updated', [
                'exam_uuid' => $exam->uuid,
                'changes'   => array_keys($fillable),
            ], 'info', $exam);

            return $exam->fresh(['questions', 'course']);
        });
    }

    /**
     * Toggle exam availability status (active <-> closed).
     */
    public function toggleStatus(User $teacher, string $uuid): Exam
    {
        $exam = $this->examRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $exam) {
            $newStatus = $exam->status === ExamStatus::ACTIVE ? ExamStatus::CLOSED : ExamStatus::ACTIVE;
            $exam->update(['status' => $newStatus]);

            $this->logSecurityEvent('teacher.exam.status_toggled', [
                'exam_uuid'  => $exam->uuid,
                'new_status' => $newStatus->value,
            ], 'info', $exam);

            return $exam->fresh(['questions', 'course']);
        });
    }

    /**
     * Toggle whether exam results and model answers are visible to students.
     */
    public function toggleResults(User $teacher, string $uuid): Exam
    {
        $exam = $this->examRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $exam) {
            $newShowResults = !$exam->show_results;
            $exam->update(['show_results' => $newShowResults]);

            $this->logSecurityEvent('teacher.exam.results_toggled', [
                'exam_uuid'    => $exam->uuid,
                'show_results' => $newShowResults,
            ], 'info', $exam);

            return $exam->fresh(['questions', 'course']);
        });
    }

    /**
     * Duplicate an existing exam with all its questions.
     */
    public function duplicateExam(User $teacher, string $uuid): Exam
    {
        $sourceExam = $this->examRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $sourceExam) {
            /** @var Exam $newExam */
            $newExam = Exam::create([
                'teacher_id'       => $teacher->id,
                'course_id'        => $sourceExam->course_id,
                'course_title_ar'  => $sourceExam->course_title_ar,
                'title_ar'         => $sourceExam->title_ar . ' (نسخة مكررة)',
                'title_en'         => $sourceExam->title_en ? $sourceExam->title_en . ' (Copy)' : null,
                'stage'            => $sourceExam->stage,
                'grade_ar'         => $sourceExam->grade_ar,
                'duration_minutes' => $sourceExam->duration_minutes,
                'full_mark'        => $sourceExam->full_mark,
                'pass_score'       => $sourceExam->pass_score,
                'questions_count'  => $sourceExam->questions_count,
                'status'           => ExamStatus::CLOSED, // new copies start closed for review
                'show_results'     => $sourceExam->show_results,
                'is_bubble_sheet'  => $sourceExam->is_bubble_sheet,
                'due_date'         => $sourceExam->due_date,
            ]);

            foreach ($sourceExam->questions as $q) {
                ExamQuestion::create([
                    'exam_id'          => $newExam->id,
                    'question_number'  => $q->question_number,
                    'type'             => $q->type,
                    'points'           => $q->points,
                    'difficulty'       => $q->difficulty,
                    'question_ar'      => $q->question_ar,
                    'question_en'      => $q->question_en,
                    'options_ar'       => $q->options_ar,
                    'correct_index'    => $q->correct_index,
                    'correct_bool'     => $q->correct_bool,
                    'model_answer_ar'  => $q->model_answer_ar,
                    'rubric_points_ar' => $q->rubric_points_ar,
                    'explanation_ar'   => $q->explanation_ar,
                ]);
            }

            $newExam->recalculateMetrics();

            $this->logSecurityEvent('teacher.exam.duplicated', [
                'source_exam_uuid' => $sourceExam->uuid,
                'new_exam_uuid'    => $newExam->uuid,
            ], 'info', $newExam);

            return $newExam->load(['questions', 'course']);
        });
    }

    /**
     * Soft delete an exam.
     */
    public function deleteExam(User $teacher, string $uuid): bool
    {
        $exam = $this->examRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $exam) {
            $examUuid = $exam->uuid;
            $exam->delete();

            $this->logSecurityEvent('teacher.exam.deleted', [
                'exam_uuid' => $examUuid,
            ], 'warning', $exam);

            return true;
        });
    }

    /**
     * Helper to synchronize child questions for an exam.
     */
    protected function syncExamQuestions(Exam $exam, array $questions): void
    {
        ExamQuestion::where('exam_id', $exam->id)->delete();

        foreach ($questions as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $typeStr = strtolower(trim((string) ($item['type'] ?? 'mcq')));
            $typeEnum = ExamQuestionType::tryFrom($typeStr) ?? ExamQuestionType::MCQ;
            $points = max(1, (int) ($item['points'] ?? 5));
            $difficulty = trim(strip_tags((string) ($item['difficulty'] ?? 'متوسط')));
            $questionAr = trim(strip_tags((string) ($item['question_ar'] ?? $item['questionAr'] ?? '')));
            $questionEn = isset($item['question_en']) || isset($item['questionEn'])
                ? trim(strip_tags((string) ($item['question_en'] ?? $item['questionEn'])))
                : null;

            // Options (MCQ)
            $options = null;
            if ($typeEnum === ExamQuestionType::MCQ) {
                $rawOptions = $item['options_ar'] ?? $item['optionsAr'] ?? [];
                if (is_array($rawOptions)) {
                    $options = array_values(array_map(fn ($o) => trim(strip_tags((string) $o)), $rawOptions));
                }
            }

            // Correct choice index (MCQ)
            $correctIndex = null;
            if ($typeEnum === ExamQuestionType::MCQ && (isset($item['correct_index']) || isset($item['correctIndex']))) {
                $correctIndex = (int) ($item['correct_index'] ?? $item['correctIndex']);
            }

            // Correct boolean (True/False)
            $correctBool = null;
            if ($typeEnum === ExamQuestionType::TRUE_FALSE && (isset($item['correct_bool']) || isset($item['correctBool']))) {
                $correctBool = (bool) ($item['correct_bool'] ?? $item['correctBool']);
            }

            // Essay fields
            $modelAnswer = null;
            $rubricPoints = null;
            if ($typeEnum === ExamQuestionType::ESSAY) {
                if (isset($item['model_answer_ar']) || isset($item['modelAnswerAr'])) {
                    $modelAnswer = trim(strip_tags((string) ($item['model_answer_ar'] ?? $item['modelAnswerAr'])));
                }
                $rawRubric = $item['rubric_points_ar'] ?? $item['rubricPointsAr'] ?? null;
                if (is_array($rawRubric)) {
                    $rubricPoints = array_values(array_map(fn ($r) => trim(strip_tags((string) $r)), $rawRubric));
                }
            }

            $explanation = isset($item['explanation_ar']) || isset($item['explanationAr'])
                ? trim(strip_tags((string) ($item['explanation_ar'] ?? $item['explanationAr'])))
                : null;

            ExamQuestion::create([
                'exam_id'          => $exam->id,
                'question_number'  => $idx + 1,
                'type'             => $typeEnum,
                'points'           => $points,
                'difficulty'       => $difficulty ?: 'متوسط',
                'question_ar'      => $questionAr ?: 'سؤال رقم ' . ($idx + 1),
                'question_en'      => $questionEn,
                'options_ar'       => $options,
                'correct_index'    => $correctIndex,
                'correct_bool'     => $correctBool,
                'model_answer_ar'  => $modelAnswer,
                'rubric_points_ar' => $rubricPoints,
                'explanation_ar'   => $explanation,
            ]);
        }
    }

    /**
     * Enforce free plan question bank limits.
     */
    protected function assertQuestionBankQuota(User $teacher, int $questionsCount): void
    {
        $profile = TeacherProfile::where('user_id', $teacher->id)->first();
        $tier = $profile?->plan_tier ?? TeacherPlanTier::PLUS;

        $max = $tier->maxQuestionsBank();
        if ($max !== null && $questionsCount > $max) {
            throw ValidationException::withMessages([
                'questions' => [__('messages.quota_question_bank_exceeded', ['max' => $max])],
            ]);
        }
    }
}

