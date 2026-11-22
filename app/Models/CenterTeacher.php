<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterTeacher extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'branch_id',
        'teacher_user_id',
        'name_ar',
        'name_en',
        'subject_ar',
        'subject_en',
        'phone',
        'email',
        'settlement_rate',
        'status',
        'invite_token_hash',
        'invite_sent_at',
        'activated_at',
    ];

    protected $hidden = [
        'invite_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'settlement_rate' => 'float',
            'invite_sent_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }

    public function teacherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_user_id');
    }

    public function studyGroups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'center_teacher_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CenterTeacherSettlement::class, 'center_teacher_id');
    }

    /**
     * Generate a cryptographically secure activation token and its SHA-256 hash.
     *
     * @return array{plain: string, hash: string}
     */
    public static function generateActivationToken(): array
    {
        $plain = bin2hex(random_bytes(24));

        return [
            'plain' => $plain,
            'hash' => hash('sha256', $plain),
        ];
    }
}
