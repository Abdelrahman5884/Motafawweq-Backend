<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;

class SaveLessonConceptGraphRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $treeData = $this->input('tree_data') ?? $this->input('treeData') ?? $this->input('notebookMindMap');
        if (is_array($treeData)) {
            $this->merge(['tree_data' => $treeData]);
        }
    }

    public function rules(): array
    {
        return [
            'tree_data' => ['required', 'array'],
            'source'    => ['nullable', 'string', 'in:manual,ai'],
        ];
    }
}

