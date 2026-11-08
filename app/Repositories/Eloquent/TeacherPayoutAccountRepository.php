<?php

namespace App\Repositories\Eloquent;

use App\Enums\PayoutAccountType;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use App\Repositories\Contracts\TeacherPayoutAccountRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TeacherPayoutAccountRepository extends BaseRepository implements TeacherPayoutAccountRepositoryInterface
{
    public function __construct(TeacherPayoutAccount $model)
    {
        parent::__construct($model);
    }

    public function getAllForTeacher(int $teacherId): Collection
    {
        return $this->model
            ->forTeacher($teacherId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * Find a payout account by UUID and strictly enforce ownership.
     */
    public function findByUuidForTeacher(string $uuid, User $teacher): TeacherPayoutAccount
    {
        /** @var TeacherPayoutAccount|null $account */
        $account = $this->model->where('uuid', $uuid)->first();

        if (!$account) {
            throw (new ModelNotFoundException())->setModel(TeacherPayoutAccount::class, [$uuid]);
        }

        // Strict anti-IDOR check (logs security warning & throws AuthorizationException if owned by another teacher)
        $account->assertOwnedBy($teacher);

        return $account;
    }

    /**
     * Ensure default receiving channels exist for newly initialized teacher accounts if none exist yet.
     */
    public function seedDefaultsIfEmpty(User $teacher): Collection
    {
        $existing = $this->getAllForTeacher($teacher->id);
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $emailPrefix = explode('@', $teacher->email)[0] ?: 'teacher';
        $phone = $teacher->phone ?: '01012345678';

        $this->model->create([
            'teacher_id'         => $teacher->id,
            'type'               => PayoutAccountType::INSTAPAY,
            'label_ar'           => 'عنوان إنستاباي الفوري (InstaPay)',
            'label_en'           => 'InstaPay Direct IPA',
            'account_identifier' => "{$emailPrefix}@instapay",
            'is_default'         => true,
            'is_active'          => true,
        ]);

        $this->model->create([
            'teacher_id'         => $teacher->id,
            'type'               => PayoutAccountType::VODAFONE,
            'label_ar'           => 'محفظة فودافون كاش ومحافظ المحمول',
            'label_en'           => 'Vodafone Cash & Mobile Wallet',
            'account_identifier' => $phone,
            'is_default'         => false,
            'is_active'          => true,
        ]);

        return $this->getAllForTeacher($teacher->id);
    }
}

