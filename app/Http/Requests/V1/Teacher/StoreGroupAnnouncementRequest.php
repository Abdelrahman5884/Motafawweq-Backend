<?php

namespace App\Http\Requests\V1\Teacher;

use App\Models\GroupAnnouncement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGroupAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', Rule::in(GroupAnnouncement::TYPES)],
            'message' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'يرجى كتابة نص التنبيه قبل الإرسال.',
            'message.min' => 'يجب أن يحتوي نص التنبيه على 3 أحرف على الأقل.',
            'message.max' => 'يجب ألا يتجاوز نص التنبيه 1000 حرف.',
            'type.in' => 'نوع التنبيه المختار غير صالح.',
        ];
    }
}
