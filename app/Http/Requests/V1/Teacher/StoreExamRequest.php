<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\ExamQuestionType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $titleAr = $this->input('title_ar') ?? $this->input('titleAr');
        $titleEn = $this->input('title_en') ?? $this->input('titleEn');
        $courseId = $this->input('course_id') ?? $this->input('courseId');
        $courseTitleAr = $this->input('course_title_ar') ?? $this->input('courseTitleAr');
        $durationMinutes = $this->input('duration_minutes') ?? $this->input('durationMinutes');
        $fullMark = $this->input('full_mark') ?? $this->input('fullMark');
        $passScore = $this->input('pass_score') ?? $this->input('passScore');
        $dueDate = $this->input('due_date') ?? $this->input('dueDate');
        $isBubbleSheet = $this->input('is_bubble_sheet') ?? $this->input('isBubbleSheet');
        $showResults = $this->input('show_results') ?? $this->input('showResults');

        $merged = [];
        if ($titleAr !== null) {
            $merged['title_ar'] = trim(strip_tags((string) $titleAr));
        }
        if ($titleEn !== null) {
            $merged['title_en'] = trim(strip_tags((string) $titleEn));
        }
        if ($courseId !== null) {
            $merged['course_id'] = $courseId;
        }
        if ($courseTitleAr !== null) {
            $merged['course_title_ar'] = trim(strip_tags((string) $courseTitleAr));
        }
        if ($durationMinutes !== null) {
            $merged['duration_minutes'] = (int) $durationMinutes;
        }
        if ($fullMark !== null) {
            $merged['full_mark'] = (int) $fullMark;
        }
        if ($passScore !== null) {
            $merged['pass_score'] = (int) $passScore;
        }
        if ($dueDate !== null) {
            $merged['due_date'] = trim((string) $dueDate);
        }
        if ($isBubbleSheet !== null) {
            $merged['is_bubble_sheet'] = filter_var($isBubbleSheet, FILTER_VALIDATE_BOOLEAN);
        }
        if ($showResults !== null) {
            $merged['show_results'] = filter_var($showResults, FILTER_VALIDATE_BOOLEAN);
        }

        $rawQuestions = $this->input('questions');
        if (is_array($rawQuestions) && !empty($rawQuestions)) {
            $merged['questions'] = array_values(array_map(function ($q, $idx) {
                if (!is_array($q)) return $q;
                $qText = trim(strip_tags((string) ($q['question_ar'] ?? $q['questionAr'] ?? '')));
                if ($qText === '' || mb_strlen($qText) < 2) {
                    $qText = 'سؤال رقم ' . ($idx + 1);
                }
                $points = isset($q['points']) ? max(1, (int) round((float) $q['points'])) : 5;
                $type = strtolower(trim((string) ($q['type'] ?? 'mcq')));
                if (!in_array($type, ['mcq', 'true_false', 'essay'], true)) {
                    $type = 'mcq';
                }

                $normalized = [
                    'type'         => $type,
                    'points'       => $points,
                    'question_ar'  => $qText,
                    'questionAr'   => $qText,
                    'difficulty'   => trim(strip_tags((string) ($q['difficulty'] ?? 'متوسط'))),
                ];

                if ($type === 'mcq') {
                    $rawOpts = $q['options_ar'] ?? $q['optionsAr'] ?? [];
                    if (!is_array($rawOpts) || empty($rawOpts)) {
                        $rawOpts = ['الخيار 1', 'الخيار 2', 'الخيار 3', 'الخيار 4'];
                    }
                    $opts = array_values(array_map(fn ($o) => trim(strip_tags((string) $o)), $rawOpts));
                    if (count($opts) < 2) {
                        $opts = array_merge($opts, ['الخيار الثاني', 'الخيار الثالث']);
                    }
                    $normalized['options_ar'] = array_slice($opts, 0, 6);
                    $normalized['optionsAr'] = $normalized['options_ar'];

                    $cIdx = (int) ($q['correct_index'] ?? $q['correctIndex'] ?? 0);
                    $normalized['correct_index'] = min(count($normalized['options_ar']) - 1, max(0, $cIdx));
                    $normalized['correctIndex'] = $normalized['correct_index'];
                } elseif ($type === 'true_false') {
                    $cBool = isset($q['correct_bool']) || isset($q['correctBool'])
                        ? filter_var($q['correct_bool'] ?? $q['correctBool'], FILTER_VALIDATE_BOOLEAN)
                        : true;
                    $normalized['correct_bool'] = $cBool;
                    $normalized['correctBool'] = $cBool;
                } elseif ($type === 'essay') {
                    $mAns = trim(strip_tags((string) ($q['model_answer_ar'] ?? $q['modelAnswerAr'] ?? '')));
                    $normalized['model_answer_ar'] = $mAns;
                    $normalized['modelAnswerAr'] = $mAns;

                    $rubric = $q['rubric_points_ar'] ?? $q['rubricPointsAr'] ?? [];
                    if (is_array($rubric)) {
                        $normalized['rubric_points_ar'] = array_values(array_slice($rubric, 0, 10));
                        $normalized['rubricPointsAr'] = $normalized['rubric_points_ar'];
                    }
                }

                if (isset($q['explanation_ar']) || isset($q['explanationAr'])) {
                    $exp = trim(strip_tags((string) ($q['explanation_ar'] ?? $q['explanationAr'])));
                    $normalized['explanation_ar'] = $exp;
                    $normalized['explanationAr'] = $exp;
                }

                return $normalized;
            }, $rawQuestions, array_keys($rawQuestions)));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'title_ar'          => ['required', 'string', 'min:3', 'max:200'],
            'titleAr'           => ['nullable', 'string', 'max:200'],
            'title_en'          => ['nullable', 'string', 'max:200'],
            'titleEn'           => ['nullable', 'string', 'max:200'],
            'course_id'         => ['nullable', 'string'],
            'courseId'          => ['nullable', 'string'],
            'course_title_ar'   => ['nullable', 'string', 'max:200'],
            'courseTitleAr'     => ['nullable', 'string', 'max:200'],
            'stage'             => ['nullable', 'string', 'max:50'],
            'grade_ar'          => ['nullable', 'string', 'max:100'],
            'duration_minutes'  => ['nullable', 'integer', 'min:1', 'max:360'],
            'durationMinutes'   => ['nullable', 'integer', 'min:1', 'max:360'],
            'full_mark'         => ['nullable', 'integer', 'min:1', 'max:1000'],
            'fullMark'          => ['nullable', 'integer', 'min:1', 'max:1000'],
            'pass_score'        => ['nullable', 'integer', 'min:0', 'max:1000'],
            'passScore'         => ['nullable', 'integer', 'min:0', 'max:1000'],
            'due_date'          => ['nullable', 'date_format:Y-m-d'],
            'dueDate'           => ['nullable', 'date_format:Y-m-d'],
            'is_bubble_sheet'   => ['nullable', 'boolean'],
            'isBubbleSheet'     => ['nullable', 'boolean'],
            'show_results'      => ['nullable', 'boolean'],
            'showResults'       => ['nullable', 'boolean'],
            'status'            => ['nullable', 'string', Rule::in(['active', 'closed', 'scheduled'])],

            // Questions Matrix
            'questions'                        => ['required', 'array', 'min:1', 'max:200'],
            'questions.*.type'                 => ['required', 'string', Rule::in(ExamQuestionType::values())],
            'questions.*.points'               => ['required', 'integer', 'min:1', 'max:100'],
            'questions.*.questionAr'           => ['nullable', 'string', 'min:2'],
            'questions.*.question_ar'          => ['nullable', 'string', 'min:2'],
            'questions.*.difficulty'           => ['nullable', 'string', 'max:50'],
            'questions.*.optionsAr'            => ['nullable', 'array', 'max:6'],
            'questions.*.options_ar'           => ['nullable', 'array', 'max:6'],
            'questions.*.correctIndex'         => ['nullable', 'integer', 'min:0', 'max:5'],
            'questions.*.correct_index'        => ['nullable', 'integer', 'min:0', 'max:5'],
            'questions.*.correctBool'          => ['nullable', 'boolean'],
            'questions.*.correct_bool'         => ['nullable', 'boolean'],
            'questions.*.modelAnswerAr'        => ['nullable', 'string'],
            'questions.*.model_answer_ar'      => ['nullable', 'string'],
            'questions.*.rubricPointsAr'       => ['nullable', 'array', 'max:10'],
            'questions.*.rubric_points_ar'      => ['nullable', 'array', 'max:10'],
            'questions.*.explanationAr'        => ['nullable', 'string'],
            'questions.*.explanation_ar'       => ['nullable', 'string'],
        ];
    }
}

