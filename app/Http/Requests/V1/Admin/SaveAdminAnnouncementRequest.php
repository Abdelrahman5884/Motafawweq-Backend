<?php

namespace App\Http\Requests\V1\Admin;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementPriority;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class SaveAdminAnnouncementRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $titleAr = $this->input('title_ar', $this->input('titleAr', $this->input('title')));
        $titleEn = $this->input('title_en', $this->input('titleEn'));
        $contentAr = $this->input('content_ar', $this->input('contentAr', $this->input('content')));
        $contentEn = $this->input('content_en', $this->input('contentEn'));

        $isActive = null;
        if ($this->has('is_active')) {
            $isActive = $this->input('is_active');
        } elseif ($this->has('isActive')) {
            $isActive = $this->input('isActive');
        } elseif ($this->has('active')) {
            $isActive = $this->input('active');
        }

        $this->merge(array_filter([
            'title_ar' => $titleAr ?: $titleEn,
            'title_en' => $titleEn ?: $titleAr,
            'content_ar' => $contentAr ?: $contentEn,
            'content_en' => $contentEn ?: $contentAr,
            'is_active' => $isActive,
            'starts_at' => $this->input('starts_at', $this->input('startsAt')),
            'expires_at' => $this->input('expires_at', $this->input('expiresAt')),
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'title_ar' => [...$req, 'string', 'min:3', 'max:220'],
            'title_en' => ['nullable', 'string', 'max:220'],
            'content_ar' => [...$req, 'string', 'min:5', 'max:2000'],
            'content_en' => ['nullable', 'string', 'max:2000'],
            'audience' => ['sometimes', 'string', Rule::in(AnnouncementAudience::values())],
            'priority' => ['sometimes', 'string', Rule::in(AnnouncementPriority::values())],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', $this->filled('starts_at') ? 'after:starts_at' : 'after:now'],
        ];
    }
}
