<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use App\Models\RepublicLeague;
use Closure;

class SaveAdminChampionRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $rawGrade = $this->input('grade_ar', $this->input('gradeAr'));
        $normalizedGrade = is_string($rawGrade) && trim($rawGrade) !== ''
            ? (RepublicLeague::normalizeGrade($rawGrade) ?? trim($rawGrade))
            : null;

        $map = [
            'student_id' => $this->input('student_id', $this->input('studentId')),
            'republic_league_id' => $this->input('republic_league_id', $this->input('republicLeagueId')),
            'champion_uuid' => $this->input('champion_uuid', $this->input('championUuid')),
            'source' => $this->input('source'),
            'name_ar' => $this->input('name_ar', $this->input('nameAr')),
            'name_en' => $this->input('name_en', $this->input('nameEn')),
            'title_ar' => $this->input('title_ar', $this->input('titleAr')),
            'title_en' => $this->input('title_en', $this->input('titleEn')),
            'grade_ar' => $normalizedGrade,
            'month_ar' => $this->input('month_ar', $this->input('monthAr')),
            'month_en' => $this->input('month_en', $this->input('monthEn')),
            'school_ar' => $this->input('school_ar', $this->input('schoolAr')),
            'school_en' => $this->input('school_en', $this->input('schoolEn')),
            'score_ar' => $this->input('score_ar', $this->input('scoreAr')),
            'score_en' => $this->input('score_en', $this->input('scoreEn')),
            'rank_ar' => $this->input('rank_ar', $this->input('rankAr')),
            'rank_en' => $this->input('rank_en', $this->input('rankEn')),
            'streak_ar' => $this->input('streak_ar', $this->input('streakAr')),
            'streak_en' => $this->input('streak_en', $this->input('streakEn')),
            'badge_ar' => $this->input('badge_ar', $this->input('badgeAr')),
            'badge_en' => $this->input('badge_en', $this->input('badgeEn')),
            'image_url' => $this->input('image_url', $this->input('image')),
            'is_pinned' => $this->has('is_pinned')
                ? $this->input('is_pinned')
                : ($this->has('isPinned') ? $this->input('isPinned') : null),
            'display_order' => $this->input('display_order', $this->input('displayOrder')),
            'added_date' => $this->input('added_date', $this->input('addedDate')),
        ];

        $this->merge(array_filter($map, fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $nameRule = $isUpdate
            ? ['sometimes', 'required', 'string', 'min:2', 'max:160']
            : ['required', 'string', 'min:2', 'max:160'];

        return [
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
            'republic_league_id' => ['nullable', 'integer', 'exists:republic_leagues,id'],
            'champion_uuid' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'in:manual,republic_league'],
            'name_ar' => $nameRule,
            'name_en' => ['nullable', 'string', 'max:160'],
            'title_ar' => ['nullable', 'string', 'max:220'],
            'title_en' => ['nullable', 'string', 'max:220'],
            'grade_ar' => ['nullable', 'string', 'max:100'],
            'month_ar' => ['nullable', 'string', 'max:120'],
            'month_en' => ['nullable', 'string', 'max:120'],
            'school_ar' => ['nullable', 'string', 'max:220'],
            'school_en' => ['nullable', 'string', 'max:220'],
            'score_ar' => ['nullable', 'string', 'max:80'],
            'score_en' => ['nullable', 'string', 'max:80'],
            'rank_ar' => ['nullable', 'string', 'max:120'],
            'rank_en' => ['nullable', 'string', 'max:120'],
            'streak_ar' => ['nullable', 'string', 'max:80'],
            'streak_en' => ['nullable', 'string', 'max:80'],
            'badge_ar' => ['nullable', 'string', 'max:140'],
            'badge_en' => ['nullable', 'string', 'max:140'],
            'image_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! self::isSafeImageUrl((string) $value)) {
                        $fail(__('messages.admin.invalid_image_url'));
                    }
                },
            ],
            'is_pinned' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'added_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Validate image path/URL strictly against XSS (javascript:/data:) and SSRF (private/loopback hosts).
     */
    public static function isSafeImageUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return true;
        }

        // Allow safe relative static asset & uploaded storage paths (no directory traversal)
        if (str_starts_with($trimmed, '/')) {
            return ! str_contains($trimmed, '..')
                && preg_match('#^/(characters|images|storage|assets)/[A-Za-z0-9._\-/]+$#', $trimmed) === 1;
        }

        // Otherwise require strict HTTPS public URL
        if (! str_starts_with(strtolower($trimmed), 'https://')) {
            return false;
        }

        $parsed = parse_url($trimmed);
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'], true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
    }
}
