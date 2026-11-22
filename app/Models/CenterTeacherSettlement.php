<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CenterTeacherSettlement extends Model
{
    use BelongsToCenter, HasFactory;

    protected $fillable = [
        'center_id',
        'center_teacher_id',
        'period_key',
        'groups_count',
        'paying_students_count',
        'gross_collected_egp',
        'settlement_rate',
        'teacher_share_egp',
        'center_share_egp',
        'status',
        'approved_by',
        'approved_at',
        'paid_at',
        'reference_note',
    ];

    protected function casts(): array
    {
        return [
            'groups_count' => 'integer',
            'paying_students_count' => 'integer',
            'gross_collected_egp' => 'float',
            'settlement_rate' => 'float',
            'teacher_share_egp' => 'float',
            'center_share_egp' => 'float',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function centerTeacher(): BelongsTo
    {
        return $this->belongsTo(CenterTeacher::class, 'center_teacher_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
