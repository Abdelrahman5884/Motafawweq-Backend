<?php

namespace App\Http\Requests\V1\Admin;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreAdminPayoutRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(array_filter([
            'recipient_user_id' => $this->input('recipient_user_id', $this->input('recipientUserId')),
            'recipient_type' => $this->input('recipient_type', $this->input('recipientType')),
            'recipient_name_ar' => $this->input('recipient_name_ar', $this->input('recipientNameAr', $this->input('teacherName'))),
            'recipient_name_en' => $this->input('recipient_name_en', $this->input('recipientNameEn')),
            'amount_egp' => $this->input('amount_egp', $this->input('amountEgp')),
            'payout_method' => $this->input('payout_method', $this->input('payoutMethod')),
            'method_label_ar' => $this->input('method_label_ar', $this->input('methodLabelAr')),
            'method_label_en' => $this->input('method_label_en', $this->input('methodLabelEn')),
            'notes_ar' => $this->input('notes_ar', $this->input('notesAr', $this->input('notes'))),
            'notes_en' => $this->input('notes_en', $this->input('notesEn')),
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'recipient_type' => ['required', 'string', Rule::in(['teacher', 'center'])],
            'recipient_name_ar' => ['required', 'string', 'min:2', 'max:180'],
            'recipient_name_en' => ['nullable', 'string', 'max:180'],
            'amount_egp' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'payout_method' => ['required', 'string', Rule::in(['vodafone', 'instapay', 'bank', 'fawry'])],
            'method_label_ar' => ['nullable', 'string', 'max:180'],
            'method_label_en' => ['nullable', 'string', 'max:180'],
            'destination' => ['nullable', 'string', 'max:180'],
            'notes_ar' => ['nullable', 'string', 'max:1000'],
            'notes_en' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
