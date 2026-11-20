<?php

namespace App\Models;

use App\Traits\BelongsToStudent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentDailyActivity extends Model
{
    use BelongsToStudent, HasFactory;

    protected $fillable = [
        'uuid',
        'student_id',
        'activity_date',
        'active_seconds',
        'visits_count',
        'first_seen_at',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'active_seconds' => 'integer',
            'visits_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }
}
