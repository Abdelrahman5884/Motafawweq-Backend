<?php

namespace App\Http\Resources\V1\Student;

use App\Enums\ExamQuestionType;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class StudentExamQuestionResource extends BaseJsonResource
{
    /**
     * Transform the question for student test-taking (SECURITY: strictly hides answer keys).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof ExamQuestionType
            ? $this->type->value
            : (string) ($this->type ?? 'mcq');

        $typeNameAr = $this->type instanceof ExamQuestionType
            ? $this->type->labelAr()
            : match ($type) {
                'true_false' => 'صح وخطأ',
                'essay'      => 'مقالي',
                default      => 'اختيار من متعدد',
            };

        return [
            'id'          => $this->uuid,
            'number'      => (int) $this->question_number,
            'points'      => (int) $this->points,
            'type'        => $type,
            'typeNameAr'  => $typeNameAr,
            'difficulty'  => $this->difficulty ?? 'متوسط',
            'questionAr'  => $this->question_ar,
            'questionEn'  => $this->question_en,
            'optionsAr'   => $this->options_ar ?? [],
            // Security: correctIndex, correctBool, modelAnswerAr & explanationAr are omitted
        ];
    }
}

