<?php

namespace App\Models;

use App\Enums\CenterStaffRole;
use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterStaff extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $table = 'center_staff';

    protected $fillable = [
        'center_id',
        'branch_id',
        'user_id',
        'name_ar',
        'name_en',
        'phone',
        'email',
        'role',
        'role_ar',
        'salary_egp',
        'permissions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'role' => CenterStaffRole::class,
            'salary_egp' => 'float',
            'permissions' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
