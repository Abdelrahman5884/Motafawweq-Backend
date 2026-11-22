<?php

namespace App\Models;

use App\Traits\BelongsToCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CenterAnnouncement extends Model
{
    use BelongsToCenter, HasFactory, SoftDeletes;

    protected $fillable = [
        'center_id',
        'branch_id',
        'title_ar',
        'content_ar',
        'author_name',
        'branch_label',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CenterBranch::class, 'branch_id');
    }
}
