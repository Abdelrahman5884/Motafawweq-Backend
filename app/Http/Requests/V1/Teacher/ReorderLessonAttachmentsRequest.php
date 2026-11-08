<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\LessonAttachmentType;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ReorderLessonAttachmentsRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $orderedIds = $this->input('ordered_ids') ?? $this->input('orderedIds') ?? $this->input('ids');
        if (is_array($orderedIds)) {
            $this->merge(['ordered_ids' => $orderedIds]);
        }
    }

    public function rules(): array
    {
        return [
            'ordered_ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ordered_ids.*' => ['required', 'uuid'],
            'type'          => ['nullable', 'string', Rule::in(LessonAttachmentType::values())],
        ];
    }
}

