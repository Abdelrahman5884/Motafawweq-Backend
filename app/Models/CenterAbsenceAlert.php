<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CenterAbsenceAlert extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'center_id',
        'study_group_id',
        'group_enrollment_id',
        'student_id',
        'session_date',
        'parent_phone',
        'channel',
        'message_ar',
        'status',
        'sent_by',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(GroupEnrollment::class, 'group_enrollment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
