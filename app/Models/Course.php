<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory, SoftDeletes, BelongsToTeacher;

    /**
     * SECURITY:
     * students_count, revenue_egp, and completion_rate are system-computed metrics
     * and are intentionally excluded from direct untrusted mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'teacher_id',
        'title_ar',
        'title_en',
        'stage_id',
        'stage_name_ar',
        'grade_ar',
        'subject_ar',
        'subject_en',
        'price_egp',
        'description_ar',
        'description_en',
        'cover_url',
        'hours_total',
        'lessons_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status'          => CourseStatus::class,
            'price_egp'       => 'float',
            'revenue_egp'     => 'float',
            'lessons_count'   => 'integer',
            'students_count'  => 'integer',
            'completion_rate' => 'integer',
        ];
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
