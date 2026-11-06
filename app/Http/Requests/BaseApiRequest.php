<?php

namespace App\Http\Requests;

use App\Traits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
        $secrets = $this->only(\App\Http\Middleware\SanitizeInput::EXCEPT);
        $sanitized = $this->sanitizeInput($this->except(\App\Http\Middleware\SanitizeInput::EXCEPT));
        $this->replace(array_merge($sanitized, $secrets));
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
            $clean = trim($input);
            $clean = str_replace(chr(0), '', $clean);
            $clean = strip_tags($clean);

            return $clean;
        }

        return $input;
    }

    /**
     * Handle a failed validation attempt with standardized localized JSON.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            $this->validationErrorResponse(
                $validator->errors()->toArray(),
                __('messages.validation_failed')
            )
        );
    }

    /**
     * Handle a failed authorization attempt with standardized localized JSON.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            $this->forbiddenResponse(__('messages.forbidden'))
        );
    }
}
