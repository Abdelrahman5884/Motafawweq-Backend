<?php

namespace App\Http\Resources\V1\Teacher;

use App\Models\TeacherInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeacherInvoice
 */
class TeacherInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->uuid,
            'uuid'          => $this->uuid,
            'invoiceNumber' => $this->invoice_number,
            'date'          => $this->paid_at ? $this->paid_at->format('Y-m-d') : $this->created_at->format('Y-m-d'),
            'descriptionAr' => $this->description_ar,
            'descriptionEn' => $this->description_en,
            'baseAmount'    => (float) $this->base_amount_egp,
            'discountAmount'=> (float) $this->discount_amount_egp,
            'finalAmount'   => (float) $this->final_amount_egp,
            'amount'        => "{$this->final_amount_egp} ج.م",
            'amountEn'      => "{$this->final_amount_egp} EGP",
            'method'        => $this->payment_method?->value ?? 'vodafone',
            'methodAr'      => $this->payment_method?->label() ?? 'فودافون كاش ومحافظ المحمول',
            'methodEn'      => ucfirst($this->payment_method?->value ?? 'vodafone'),
            'status'        => $this->status?->value ?? 'paid',
            'statusAr'      => $this->status?->label() ?? 'مدفوع',
            'paidAt'        => $this->paid_at?->format('Y-m-d H:i'),
            'createdAt'     => $this->created_at?->toIso8601String(),
        ];
    }
}

