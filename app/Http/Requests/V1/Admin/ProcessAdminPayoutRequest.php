<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;

class ProcessAdminPayoutRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(array_filter([
            'transfer_reference' => $this->input('transfer_reference', $this->input('transferReference')),
            'rejection_reason' => $this->input('rejection_reason', $this->input('rejectionReason', $this->input('reason'))),
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transfer_reference' => ['nullable', 'string', 'max:120'],
            'rejection_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
