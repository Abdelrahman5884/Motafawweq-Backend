<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CourseCertificate extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    protected $fillable = [
        'certificate_number',
        'signature_hash',
        'teacher_id',
        'student_id',
        'course_id',
        'student_name_ar',
        'student_name_en',
        'student_email',
        'student_phone',
        'course_name_ar',
        'course_name_en',
        'grade_ar',
        'grade_percent',
        'honors_title_ar',
        'honors_title_en',
        'issue_date',
        'status',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'        => CertificateStatus::class,
            'issue_date'    => 'date',
            'revoked_at'    => 'datetime',
            'grade_percent' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->certificate_number)) {
                $model->certificate_number = static::generateUniqueCertNumber();
            }

            if (empty($model->signature_hash)) {
                $rawDate = $model->issue_date instanceof \DateTimeInterface
                    ? $model->issue_date->format('Y-m-d')
                    : (string) ($model->issue_date ?? now()->toDateString());

                $model->signature_hash = static::computeSignatureHash(
                    $model->certificate_number,
                    (int) $model->teacher_id,
                    (string) $model->student_name_ar,
                    (float) $model->grade_percent,
                    $rawDate
                );
            }
        });
    }

    public static function generateUniqueCertNumber(string $subjectPrefix = 'BIO'): string
    {
        $year = date('Y');
        do {
            $suffix = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $number = "MTF-{$subjectPrefix}-{$year}-{$suffix}";
        } while (static::where('certificate_number', $number)->exists());

        return $number;
    }

    public static function computeSignatureHash(
        string $certNumber,
        int $teacherId,
        string $studentName,
        float $grade,
        string $date
    ): string {
        $secret = config('app.key') ?: 'motafawweq_master_secret_2026';
        $normalizedDate = substr(trim($date), 0, 10);
        $payload = "{$certNumber}|{$teacherId}|{$studentName}|{$grade}|{$normalizedDate}";

        return hash_hmac('sha256', $payload, $secret);
    }

    public function isValidSignature(): bool
    {
        $rawDate = $this->issue_date instanceof \DateTimeInterface
            ? $this->issue_date->format('Y-m-d')
            : (string) ($this->issue_date ?? '');

        $computed = static::computeSignatureHash(
            $this->certificate_number,
            (int) $this->teacher_id,
            (string) $this->student_name_ar,
            (float) $this->grade_percent,
            $rawDate
        );

        return hash_equals($computed, (string) $this->signature_hash);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }
}
