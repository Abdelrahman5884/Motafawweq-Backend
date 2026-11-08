<?php

namespace App\Http\Resources\V1\Teacher;

use App\Enums\PayoutAccountType;
use App\Http\Resources\BaseJsonResource;
use Illuminate\Http\Request;

class TeacherPayoutAccountResource extends BaseJsonResource
{
    /**
     * Transform the encrypted payout account into the frontend payoutMethod structure.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof PayoutAccountType
            ? $this->type
            : PayoutAccountType::tryFrom((string) $this->type);

        $identifier = (string) $this->account_identifier;

        return array_filter([
            'id'            => $this->uuid,
            'type'          => $type?->value ?? (string) $this->type,
            'labelAr'       => $this->label_ar,
            'labelEn'       => $this->label_en,
            'address'       => $type === PayoutAccountType::INSTAPAY ? $identifier : null,
            'phone'         => $type === PayoutAccountType::VODAFONE ? $identifier : null,
            'accountNumber' => in_array($type, [PayoutAccountType::CIB, PayoutAccountType::CARD], true) ? $identifier : null,
            'value'         => $identifier,
            'maskedValue'   => $this->maskedIdentifier(),
            'isDefault'     => (bool) $this->is_default,
            'isActive'      => (bool) $this->is_active,
            'createdAt'     => $this->formatDate($this->created_at),
        ], fn ($val) => $val !== null);
    }
}

