<?php

namespace App\Http\Requests\V1\Center;

use App\Enums\CenterExpenseCategory;
use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class StoreCenterExpenseRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'min:2', 'max:220'],
            'title_en' => ['nullable', 'string', 'max:220'],
            'category' => ['required', 'string', Rule::in(CenterExpenseCategory::values())],
            'amount_egp' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'branch_id' => ['nullable', 'uuid'],
            'expense_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in(['paid', 'pending'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $map = [
            'titleAr' => 'title_ar',
            'titleEn' => 'title_en',
            'amountEgp' => 'amount_egp',
            'branchId' => 'branch_id',
            'date' => 'expense_date',
        ];

        $merged = [];
        foreach ($map as $camel => $snake) {
            if (! $this->has($snake) && $this->has($camel)) {
                $merged[$snake] = $this->input($camel);
            }
        }
        if (! empty($merged)) {
            $this->merge($merged);
        }
    }
}
