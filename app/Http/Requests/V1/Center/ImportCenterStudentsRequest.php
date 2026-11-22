<?php

namespace App\Http\Requests\V1\Center;

use App\Http\Requests\BaseApiRequest;

class ImportCenterStudentsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'default_group_id' => ['nullable', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*.name' => ['required', 'string', 'min:2', 'max:150'],
            'rows.*.phone' => ['required', 'string', 'max:40'],
            'rows.*.group' => ['nullable', 'string', 'max:200'],
            'rows.*.grade' => ['nullable', 'string', 'max:100'],
            'rows.*.parent_name' => ['nullable', 'string', 'max:150'],
            'rows.*.parent_phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->has('default_group_id') && $this->filled('defaultGroupId')) {
            $this->merge(['default_group_id' => $this->input('defaultGroupId')]);
        }
    }
}
