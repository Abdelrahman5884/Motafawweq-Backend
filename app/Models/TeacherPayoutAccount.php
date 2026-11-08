<?php

namespace App\Models;

use App\Enums\PayoutAccountType;
use App\Traits\BelongsToTeacher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeacherPayoutAccount extends Model
{
    use HasFactory, BelongsToTeacher;

    protected $fillable = [
        'teacher_id',
        'type',
        'label_ar',
        'label_en',
        'account_identifier',
        'is_default',
        'is_active',
    ];

    /**
     * Never expose raw encrypted ciphertext directly in accidental dumps.
     *
     * @var list<string>
     */
    protected $hidden = [
        'account_identifier',
    ];

    protected function casts(): array
    {
        return [
            'type'               => PayoutAccountType::class,
            'account_identifier' => 'encrypted',
            'is_default'         => 'boolean',
            'is_active'          => 'boolean',
        ];
    }

    /**
     * Return a safely masked representation of the identifier for security audit logs.
     */
    public function maskedIdentifier(): string
    {
        $plain = (string) $this->account_identifier;
        $len = mb_strlen($plain);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return mb_substr($plain, 0, 3) . str_repeat('*', max(3, $len - 6)) . mb_substr($plain, -3);
    }
}

