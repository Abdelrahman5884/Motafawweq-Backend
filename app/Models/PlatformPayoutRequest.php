<?php

namespace App\Models;

use App\Enums\PayoutRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformPayoutRequest extends Model
{
    use HasFactory;

    /**
     * SECURITY: status, processed_at, processed_by, transfer_reference, and rejection_reason
     * are NOT mass-assignable; they are mutated exclusively via atomic forceFill() inside AdminFinancialService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'recipient_user_id',
        'recipient_type',
        'recipient_name_ar',
        'recipient_name_en',
        'amount_egp',
        'payout_method',
        'method_label_ar',
        'method_label_en',
        'payout_destination_encrypted',
        'payout_destination_masked',
        'notes_ar',
        'notes_en',
        'requested_at',
    ];

    /**
     * Never leak raw encrypted destination fields in serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'payout_destination_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'amount_egp' => 'float',
            'status' => PayoutRequestStatus::class,
            'payout_destination_encrypted' => 'encrypted',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->reference_number)) {
                $model->reference_number = static::generateUniqueReference();
            }
            if (empty($model->requested_at)) {
                $model->requested_at = now();
            }
            if (empty($model->status)) {
                $model->status = PayoutRequestStatus::PENDING;
            }
        });
    }

    public static function generateUniqueReference(): string
    {
        $year = now()->format('Y');

        do {
            $ref = sprintf('PAY-%s-%06d', $year, random_int(100000, 999999));
        } while (static::where('reference_number', $ref)->exists());

        return $ref;
    }

    public static function maskDestination(?string $raw): ?string
    {
        $clean = trim((string) $raw);
        if ($clean === '') {
            return null;
        }

        $len = strlen($clean);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        if (str_contains($clean, '@')) {
            [$handle, $domain] = explode('@', $clean, 2);
            $visible = substr($handle, 0, min(2, strlen($handle)));

            return $visible.'***@'.$domain;
        }

        return str_repeat('*', max(4, $len - 4)).substr($clean, -4);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontendArray(): array
    {
        $statusVal = $this->status instanceof PayoutRequestStatus
            ? $this->status->value
            : (string) $this->status;

        $methodAr = $this->method_label_ar ?: match ($this->payout_method) {
            'vodafone' => 'فودافون كاش',
            'instapay' => 'إنستاباي',
            'bank' => 'تحويل بنكي',
            default => $this->payout_method,
        };
        $methodEn = $this->method_label_en ?: ucfirst((string) $this->payout_method);

        if ($this->payout_destination_masked && ! str_contains($methodAr, $this->payout_destination_masked)) {
            $methodAr .= " ({$this->payout_destination_masked})";
            $methodEn .= " ({$this->payout_destination_masked})";
        }

        return [
            'id' => $this->uuid,
            'referenceNumber' => $this->reference_number,
            'recipientUserId' => $this->recipient_user_id,
            'recipientType' => $this->recipient_type,
            'teacherName' => $this->recipient_name_ar,
            'teacherNameAr' => $this->recipient_name_ar,
            'teacherNameEn' => $this->recipient_name_en ?: $this->recipient_name_ar,
            'amountEgp' => (float) $this->amount_egp,
            'payoutMethod' => $this->payout_method,
            'method' => $methodAr,
            'methodAr' => $methodAr,
            'methodEn' => $methodEn,
            'destinationMasked' => $this->payout_destination_masked,
            'requestDate' => ($this->requested_at ?? $this->created_at)?->format('Y-m-d H:i'),
            'requestedAt' => ($this->requested_at ?? $this->created_at)?->toIso8601String(),
            'processedAt' => $this->processed_at?->toIso8601String(),
            'transferReference' => $this->transfer_reference,
            'rejectionReason' => $this->rejection_reason,
            'status' => $statusVal,
            'notes' => $this->notes_ar ?: '',
            'notesAr' => $this->notes_ar ?: '',
            'notesEn' => $this->notes_en ?: ($this->notes_ar ?: ''),
        ];
    }
}
