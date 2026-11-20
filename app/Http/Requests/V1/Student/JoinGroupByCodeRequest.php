<?php

namespace App\Http\Requests\V1\Student;

use App\Http\Requests\BaseApiRequest;

class JoinGroupByCodeRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStudent();
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('join_code') ?? $this->input('joinCode') ?? $this->input('code');

        if ($raw !== null) {
            $extracted = $this->extractJoinCode((string) $raw);
            $this->merge([
                'join_code' => strtoupper($extracted),
            ]);
        }
    }

    /**
     * Extract clean join code even if the student pasted a full QR join URL.
     */
    protected function extractJoinCode(string $raw): string
    {
        $trimmed = trim($raw);

        // Handle full URLs such as https://motafawweq.me/join-group/BIO-7492 or ?joinCode=BIO-7492
        if (preg_match('#(?:join-group/|joinCode=|code=)([A-Za-z0-9\-_]{4,32})#i', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $trimmed;
    }

    public function rules(): array
    {
        return [
            'join_code' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'parent_name_ar' => ['nullable', 'string', 'max:150'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'join_code.required' => 'يرجى إدخال كود المجموعة أولاً.',
            'join_code.min' => 'كود المجموعة قصير جداً، تأكد من إدخال الكود بالكامل.',
            'join_code.max' => 'كود المجموعة غير صالح.',
            'join_code.regex' => 'كود المجموعة يجب أن يحتوي على حروف إنجليزية وأرقام وشرطة فقط (مثال: GRP-7492).',
        ];
    }
}
