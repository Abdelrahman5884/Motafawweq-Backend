<?php

namespace App\Models;

use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GroupAnnouncement extends Model
{
    use BelongsToTeacher, HasFactory;

    public const TYPES = ['announcement', 'homework', 'quiz', 'reschedule'];

    protected $fillable = [
        'uuid',
        'teacher_id',
        'study_group_id',
        'type',
        'message',
        'recipients_count',
    ];

    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function studyGroup(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'study_group_id');
    }
}
