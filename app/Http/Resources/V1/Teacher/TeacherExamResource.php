<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\ExamStatus;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherExamResource extends BaseJsonResource
{
    /**
     * Transform the exam into the frontend payload for TeacherExamsView.jsx.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ExamStatus
            ? $this->status->value
            : (string) ($this->status ?? 'active');

        $questions = $this->relationLoaded('questions') ? $this->questions : collect();

        // Optional question type filtering if requested by query param
        $filterType = $request->query('question_type');
        if ($filterType && in_array($filterType, ['mcq', 'true_false', 'essay'], true)) {
            $questions = $questions->filter(fn ($q) => ($q->type instanceof \App\Enums\ExamQuestionType ? $q->type->value : (string) $q->type) === $filterType)->values();
        }

        return [
            'id'               => $this->uuid,
            'titleAr'          => $this->title_ar,
            'titleEn'          => $this->title_en ?? $this->title_ar,
            'courseId'         => $this->whenLoaded('course', fn () => $this->course?->uuid),
            'courseTitleAr'    => $this->course_title_ar ?: ($this->whenLoaded('course', fn () => $this->course?->title_ar) ?? 'المقرر الدراسي'),
            'stage'            => $this->stage,
            'gradeAr'          => $this->grade_ar,
            'durationMinutes'  => (int) $this->duration_minutes,
            'fullMark'         => (int) $this->full_mark,
            'passScore'        => (int) $this->pass_score,
            'questionsCount'   => (int) $this->questions_count,
            'status'           => $status,
            'showResults'      => (bool) $this->show_results,
            'isBubbleSheet'    => (bool) $this->is_bubble_sheet,
            'dueDate'          => $this->due_date?->toDateString(),
            'startsAt'         => $this->formatDate($this->starts_at),
            'avgScore'         => (float) $this->avg_score,
            'passRate'         => (float) $this->pass_rate,
            'topScoreCount'    => (int) $this->top_score_count,
            'submissionsCount' => (int) $this->submissions_count,
            'questions'        => TeacherExamQuestionResource::collection($questions)->resolve($request),
            'createdAt'        => $this->formatDate($this->created_at),
            'updatedAt'        => $this->formatDate($this->updated_at),
        ];
    }
}

