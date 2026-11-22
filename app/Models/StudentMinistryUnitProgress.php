<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Model;

class StudentMinistryUnitProgress extends Model
{
    use BelongsToStudent;

    protected $table = 'student_ministry_unit_progress';

    protected $fillable = [
        'uuid',
        'student_id',
        'curriculum_code',
        'unit_index',
        'is_completed',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_index' => 'integer',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}
