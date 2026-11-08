<?php

namespace App\Http\Requests\V1\Teacher;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ResolveRiskSignalRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $action = $this->input('action') ?? 'resolve';
        $notes = $this->input('notes');

        $merged = [
            'action' => strtolower(trim((string) $action)),
        ];

        if ($notes !== null) {
            $merged['notes'] = trim(strip_tags((string) $notes));
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['notify', 'notify_parent', 'resolve'])],
            'notes'  => ['nullable', 'string', 'max:500'],
        ];
    }
}

