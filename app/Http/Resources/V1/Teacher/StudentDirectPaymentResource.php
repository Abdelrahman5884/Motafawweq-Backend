<?php

namespace App\Http\Resources\V1\Teacher;

use App\Models\StudentDirectPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentDirectPayment
 */
class StudentDirectPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->uuid,
            'uuid'            => $this->uuid,
            'referenceNum'    => $this->reference_num,
            'amountEgp'       => (float) $this->amount_egp,
            'amount'          => "{$this->amount_egp} ج.م",
            'method'          => $this->payment_method?->value ?? 'vodafone',
            'methodLabelAr'   => $this->payment_method?->label() ?? 'فودافون كاش',
            'studentId'       => $this->student_id ? (string) $this->student_id : null,
            'studentNameAr'   => $this->student_name_ar,
            'studentNameEn'   => $this->student_name_en,
            'studentPhone'    => $this->student_phone,
            'courseId'        => $this->course?->uuid,
            'courseNameAr'    => $this->course_name_ar,
            'courseNameEn'    => $this->course_name_en,
            'studyGroupId'    => $this->studyGroup?->uuid,
            'studyGroupNameAr'=> $this->studyGroup?->name_ar,
            'receiptImagePath'=> $this->receipt_image_path,
            'status'          => $this->status?->value ?? 'pending',
            'statusLabelAr'   => $this->status?->label() ?? 'معلق',
            'paidAt'          => $this->paid_at?->format('Y-m-d H:i'),
            'confirmedAt'     => $this->confirmed_at?->format('Y-m-d H:i'),
            'rejectionReason' => $this->rejection_reason,
            'notes'           => $this->notes,
            'createdAt'       => $this->created_at?->toIso8601String(),
        ];
    }
}

