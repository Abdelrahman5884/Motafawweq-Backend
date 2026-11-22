<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\AttendanceRecordMethod;
use App\Enums\DayOfWeek;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class ScanCenterAttendanceRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:2', 'max:500'],
            'method' => ['nullable', 'string', Rule::in(AttendanceRecordMethod::values())],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
            'simulated_day' => ['nullable', 'string', Rule::in(DayOfWeek::values())],
            'simulated_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merged = [];
        if (! $this->has('code')) {
            foreach (['barcode', 'qr_payload', 'qrPayload', 'passcode'] as $alias) {
                if ($this->filled($alias)) {
                    $merged['code'] = (string) $this->input($alias);
                    break;
                }
            }
        }
        if (! $this->has('simulated_day') && $this->filled('simulatedDay')) {
            $merged['simulated_day'] = strtolower(trim((string) $this->input('simulatedDay')));
        } elseif ($this->filled('simulated_day')) {
            $merged['simulated_day'] = strtolower(trim((string) $this->input('simulated_day')));
        }
        if (! $this->has('simulated_time') && $this->filled('simulatedTime')) {
            $merged['simulated_time'] = (string) $this->input('simulatedTime');
        }
        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
