<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ParentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'emergency_phone',
        'notify_tuition_renewal',
        'notify_achievements_certificates',
        'preferred_lang',
        'preferred_theme',
    ];

    protected function casts(): array
    {
        return [
            'notify_tuition_renewal' => 'boolean',
            'notify_achievements_certificates' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
