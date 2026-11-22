<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterBranch extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'name_ar',
        'name_en',
        'address_ar',
        'address_en',
        'phone',
        'manager_name_ar',
        'manager_name_en',
        'is_main',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(CenterRoom::class, 'branch_id');
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(CenterTeacher::class, 'branch_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(CenterStaff::class, 'branch_id');
    }

    public function studyGroups(): HasMany
    {
        return $this->hasMany(StudyGroup::class, 'center_branch_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CenterExpense::class, 'branch_id');
    }
}
