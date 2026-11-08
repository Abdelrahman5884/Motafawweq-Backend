<?php

namespace App\Http\Requests\V1\Teacher;

use App\Enums\AttendanceRecordMethod;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ScanAttendanceRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isTeacher();
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code') ?? $this->input('barcode') ?? $this->input('token') ?? $this->input('passcode');
        $method = $this->input('method') ?? 'qr_scan';
        $notes = $this->input('notes');

        $merged = [];
        if ($code !== null) {
            $merged['code'] = trim((string) $code);
        }
        if ($method !== null) {
            $merged['method'] = strtolower(trim((string) $method));
        }
        if ($notes !== null) {
            $merged['notes'] = trim(strip_tags((string) $notes));
        }

        if (!empty($merged)) {
            $this->merge($merged);
        }
    }

    public function rules(): array
    {
        return [
            'code'   => ['required', 'string', 'min:1', 'max:255'],
            'method' => ['nullable', 'string', Rule::in(AttendanceRecordMethod::values())],
            'notes'  => ['nullable', 'string', 'max:255'],
        ];
    }
}

