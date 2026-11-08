<?php

namespace App\Models;

use App\Enums\DirectPaymentStatus;
use App\Enums\PaymentMethod;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StudentDirectPayment extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $fillable = [
        'reference_num',
        'teacher_id',
        'student_id',
        'course_id',
        'study_group_id',
        'payout_account_id',
        'amount_egp',
        'payment_method',
        'student_name_ar',
        'student_name_en',
        'student_phone',
        'course_name_ar',
        'course_name_en',
        'receipt_image_path',
        'status',
        'paid_at',
        'confirmed_at',
        'rejection_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status'         => DirectPaymentStatus::class,
            'amount_egp'     => 'float',
            'paid_at'        => 'datetime',
            'confirmed_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->reference_num)) {
                $model->reference_num = static::generateUniqueReference();
            }
        });
    }

    public static function generateUniqueReference(): string
    {
        $year = date('Y');
        do {
            $suffix = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $ref = "DIR-PAY-{$year}-{$suffix}";
        } while (static::where('reference_num', $ref)->exists());

        return $ref;
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(TeacherPayoutAccount::class, 'payout_account_id');
    }
}

