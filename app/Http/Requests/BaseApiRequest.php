<?php

namespace App\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseApiRequest extends FormRequest
{
    use ApiResponse;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data before validation:
     * Sanitize inputs (strip tags, trim strings, prevent XSS).
     */
    protected function prepareForValidation(): void
    {
        $sanitized = $this->sanitizeInput($this->all());
        $this->replace($sanitized);
    }

    /**
     * Recursive input sanitization.
     */
    protected function sanitizeInput(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }

        if (is_string($input)) {
            // Trim whitespace
            $clean = trim($input);

            // Strip potential null-bytes
            $clean = str_replace(chr(0), '', $clean);

            // Strip dangerous tags while preserving plain text
            $clean = strip_tags($clean);

            return $clean;
        }

        return $input;
    }

    /**
     * Handle a failed validation attempt with standardized JSON.
     *
     * @param  Validator  $validator
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            $this->validationErrorResponse(
                $validator->errors()->toArray(),
                'بيانات الإدخال غير صالحة، يرجى مراجعة الأخطاء'
            )
        );
    }

    /**
     * Handle a failed authorization attempt with standardized JSON.
     *
     * @throws HttpResponseException
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            $this->forbiddenResponse('غير مصرح لك بالقيام بهذا الإجراء')
        );
    }
}

