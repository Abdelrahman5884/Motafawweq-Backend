<?php

namespace App\Services\V1\Teacher;

use App\Enums\PayoutAccountType;
use App\Models\TeacherPayoutAccount;
use App\Models\User;
use App\Repositories\Contracts\TeacherPayoutAccountRepositoryInterface;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;

class TeacherPayoutAccountService extends BaseService
{
    public function __construct(
        protected TeacherPayoutAccountRepositoryInterface $payoutRepository
    ) {}

    /**
     * List all payout accounts for the teacher (seeding defaults on first visit if none exist).
     */
    public function listAccounts(User $teacher, bool $seedIfEmpty = true): Collection
    {
        if ($seedIfEmpty) {
            return $this->payoutRepository->seedDefaultsIfEmpty($teacher);
        }

        return $this->payoutRepository->getAllForTeacher($teacher->id);
    }

    /**
     * Create a new encrypted payout account for the teacher.
     */
    public function createAccount(User $teacher, array $data): TeacherPayoutAccount
    {
        return $this->transaction(function () use ($teacher, $data) {
            $type = PayoutAccountType::from((string) $data['type']);

            $identifier = trim((string) (
                $data['value']
                ?? $data['account_identifier']
                ?? $data['address']
                ?? $data['phone']
                ?? $data['accountNumber']
                ?? $data['account_number']
                ?? ''
            ));

            $customLabelAr = trim((string) ($data['labelAr'] ?? $data['label_ar'] ?? $data['label'] ?? ''));
            $customLabelEn = trim((string) ($data['labelEn'] ?? $data['label_en'] ?? $data['label'] ?? ''));

            $hasExistingDefault = TeacherPayoutAccount::forTeacher($teacher->id)
                ->where('is_default', true)
                ->exists();

            $shouldBeDefault = !$hasExistingDefault || !empty($data['isDefault']) || !empty($data['is_default']);

            if ($shouldBeDefault) {
                TeacherPayoutAccount::forTeacher($teacher->id)->update(['is_default' => false]);
            }

            /** @var TeacherPayoutAccount $account */
            $account = $this->payoutRepository->create([
                'teacher_id'         => $teacher->id,
                'type'               => $type,
                'label_ar'           => $customLabelAr !== '' ? $customLabelAr : $type->defaultLabelAr(),
                'label_en'           => $customLabelEn !== '' ? $customLabelEn : $type->defaultLabelEn(),
                'account_identifier' => $identifier,
                'is_default'         => $shouldBeDefault,
                'is_active'          => array_key_exists('isActive', $data)
                    ? (bool) $data['isActive']
                    : (array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true),
            ]);

            $this->logSecurityEvent(
                'teacher.payout_account.created',
                [
                    'type'              => $type->value,
                    'masked_identifier' => $account->maskedIdentifier(),
                    'is_default'        => $account->is_default,
                ],
                'info',
                $account
            );

            return $account;
        });
    }

    /**
     * Toggle active state of a payout account (strictly verifying teacher ownership).
     */
    public function toggleActive(User $teacher, string $uuid): TeacherPayoutAccount
    {
        $account = $this->payoutRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($account) {
            $account->update([
                'is_active' => !$account->is_active,
            ]);

            $this->logSecurityEvent(
                'teacher.payout_account.toggled',
                [
                    'type'              => $account->type->value,
                    'masked_identifier' => $account->maskedIdentifier(),
                    'is_active'         => $account->is_active,
                ],
                'info',
                $account
            );

            return $account->fresh();
        });
    }

    /**
     * Atomically set a payout account as the teacher's primary default receiving account.
     */
    public function setDefault(User $teacher, string $uuid): TeacherPayoutAccount
    {
        $account = $this->payoutRepository->findByUuidForTeacher($uuid, $teacher);

        return $this->transaction(function () use ($teacher, $account) {
            TeacherPayoutAccount::forTeacher($teacher->id)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);

            $account->update([
                'is_default' => true,
                'is_active'  => true,
            ]);

            $this->logSecurityEvent(
                'teacher.payout_account.default_changed',
                [
                    'type'              => $account->type->value,
                    'masked_identifier' => $account->maskedIdentifier(),
                ],
                'info',
                $account
            );

            return $account->fresh();
        });
    }

    /**
     * Delete a payout account and promote a fallback default if needed.
     */
    public function deleteAccount(User $teacher, string $uuid): void
    {
        $account = $this->payoutRepository->findByUuidForTeacher($uuid, $teacher);

        $this->transaction(function () use ($teacher, $account) {
            $wasDefault = $account->is_default;
            $masked = $account->maskedIdentifier();
            $type = $account->type->value;

            $account->delete();

            if ($wasDefault) {
                $nextAccount = TeacherPayoutAccount::forTeacher($teacher->id)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->first()
                    ?? TeacherPayoutAccount::forTeacher($teacher->id)->orderBy('id')->first();

                if ($nextAccount) {
                    $nextAccount->update(['is_default' => true]);
                }
            }

            $this->logSecurityEvent(
                'teacher.payout_account.deleted',
                [
                    'type'              => $type,
                    'masked_identifier' => $masked,
                    'was_default'       => $wasDefault,
                ],
                'warning'
            );
        });
    }
}
