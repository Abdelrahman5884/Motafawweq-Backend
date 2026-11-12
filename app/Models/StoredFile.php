<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class StoredFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'entity_type',
        'entity_id',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'storage_provider',
        'upload_status',
        'metadata',
    ];

    protected $hidden = [
        'storage_path',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'metadata'        => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StoredFile $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->storage_provider)) {
                $model->storage_provider = 'supabase';
            }
            if (empty($model->storage_disk)) {
                $model->storage_disk = 'supabase';
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

