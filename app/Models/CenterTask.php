<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterTask extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'branch_id',
        'assigned_staff_id',
        'title_ar',
        'assignee_name',
        'deadline_label',
        'due_at',
        'is_completed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(CenterStaff::class, 'assigned_staff_id');
    }
}
