<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterRoom extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'branch_id',
        'name_ar',
        'name_en',
        'floor',
        'capacity',
        'equipment',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'equipment' => 'array',
            'is_available' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }

    public function studyGroups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'center_room_id');
    }

    public function scheduleSlots(): HasMany
    {
        return $this->hasMany(GroupScheduleSlot::class, 'center_room_id');
    }
}
