<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CenterQueueCompletion extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'center_id',
        'study_group_id',
        'schedule_slot_id',
        'queue_date',
        'day_of_week',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }

    public function scheduleSlot(): BelongsTo
    {
        return $this->belongsTo(GroupScheduleSlot::class, 'schedule_slot_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
