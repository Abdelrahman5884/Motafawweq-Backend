<?php

namespace App\Services\V1\Teacher;

use App\Enums\UserRole;
use App\Models\CenterConnectedUser;
use App\Models\CenterProfile;
use App\Models\CenterTeacher;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TeacherCenterInvitationService extends BaseService
{
    /**
     * List incoming pending center invitations and active center affiliations for the authenticated teacher.
     *
     * @return array{invitations: array<int, array<string, mixed>>, activeCenters: array<int, array<string, mixed>>}
     */
    public function listForTeacher(User $teacher): array
    {
        $records = CenterTeacher::query()
            ->where('teacher_user_id', $teacher->id)
            ->whereIn('status', ['invited', 'active'])
            ->with([
                'branch:id,uuid,name_ar,name_en',
            ])
            ->orderByDesc('invite_sent_at')
            ->orderByDesc('created_at')
            ->get();

        $centerIds = $records->pluck('center_id')->unique()->values()->all();

        $centerUsers = User::whereIn('id', $centerIds)
            ->get(['id', 'name', 'email', 'phone', 'avatar'])
            ->keyBy('id');

        $centerProfiles = CenterProfile::whereIn('user_id', $centerIds)
            ->get(['user_id', 'name_ar', 'name_en', 'phone', 'address_ar', 'logo_url', 'is_verified_center'])
            ->keyBy('user_id');

        $invitations = [];
        $activeCenters = [];

        foreach ($records as $record) {
            $formatted = $this->formatInvitation(
                $record,
                $centerUsers->get($record->center_id),
                $centerProfiles->get($record->center_id)
            );

            if ($record->status === 'invited') {
                $invitations[] = $formatted;
            } else {
                $activeCenters[] = $formatted;
            }
        }

        return [
            'invitations' => $invitations,
            'activeCenters' => $activeCenters,
        ];
    }

    /**
     * Accept a pending center invitation as the invited platform teacher.
     *
     * @return array<string, mixed>
     */
    public function acceptInvitation(User $teacher, string $uuid): array
    {
        $record = $this->findOwnedInvitation($teacher, $uuid);

        if ($record->status === 'active') {
            return $this->loadAndFormat($record);
        }

        if ($record->status !== 'invited') {
            throw ValidationException::withMessages([
                'invitation' => ['لم تعد هذه الدعوة متاحة للقبول.'],
            ]);
        }

        $updated = $this->transaction(function () use ($teacher, $record) {
            $record->update([
                'status' => 'active',
                'activated_at' => now(),
            ]);

            CenterConnectedUser::updateOrCreate(
                [
                    'center_id' => $record->center_id,
                    'user_id' => $teacher->id,
                ],
                [
                    'branch_id' => $record->branch_id,
                    'connected_role' => UserRole::TEACHER->value,
                    'connected_by' => $record->center_id,
                    'connected_at' => now(),
                ]
            );

            $this->logSecurityEvent('teacher.center_invitation.accepted', [
                'center_teacher_uuid' => $record->uuid,
                'center_id' => $record->center_id,
                'teacher_user_id' => $teacher->id,
            ], 'info', $record);

            return $record->fresh(['branch:id,uuid,name_ar,name_en']);
        });

        return $this->loadAndFormat($updated);
    }

    /**
     * Decline a pending center invitation as the invited platform teacher.
     *
     * @return array<string, mixed>
     */
    public function declineInvitation(User $teacher, string $uuid): array
    {
        $record = $this->findOwnedInvitation($teacher, $uuid);

        if ($record->status === 'declined') {
            return $this->loadAndFormat($record);
        }

        if ($record->status !== 'invited') {
            throw ValidationException::withMessages([
                'invitation' => ['لا يمكن رفض دعوة غير معلقة.'],
            ]);
        }

        $updated = $this->transaction(function () use ($teacher, $record) {
            $record->update([
                'status' => 'declined',
                'activated_at' => null,
            ]);

            CenterConnectedUser::where('center_id', $record->center_id)
                ->where('user_id', $teacher->id)
                ->delete();

            $this->logSecurityEvent('teacher.center_invitation.declined', [
                'center_teacher_uuid' => $record->uuid,
                'center_id' => $record->center_id,
                'teacher_user_id' => $teacher->id,
            ], 'info', $record);

            return $record->fresh(['branch:id,uuid,name_ar,name_en']);
        });

        return $this->loadAndFormat($updated);
    }

    protected function findOwnedInvitation(User $teacher, string $uuid): CenterTeacher
    {
        /** @var CenterTeacher|null $record */
        $record = CenterTeacher::where('uuid', $uuid)
            ->where('teacher_user_id', $teacher->id)
            ->with(['branch:id,uuid,name_ar,name_en'])
            ->first();

        if (! $record) {
            throw (new ModelNotFoundException)->setModel(CenterTeacher::class, [$uuid]);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    protected function loadAndFormat(CenterTeacher $record): array
    {
        $centerUser = User::find($record->center_id, ['id', 'name', 'email', 'phone', 'avatar']);
        $centerProfile = CenterProfile::where('user_id', $record->center_id)
            ->first(['user_id', 'name_ar', 'name_en', 'phone', 'address_ar', 'logo_url', 'is_verified_center']);

        return $this->formatInvitation($record, $centerUser, $centerProfile);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatInvitation(CenterTeacher $record, ?User $centerUser, ?CenterProfile $profile): array
    {
        $centerNameAr = $profile?->name_ar ?: ($centerUser?->name ?: 'السنتر التعليمي');
        $centerNameEn = $profile?->name_en ?: $centerNameAr;
        $centerAvatar = $centerUser?->avatar ?: $profile?->logo_url;

        return [
            'id' => $record->uuid,
            'uuid' => $record->uuid,
            'centerId' => $record->center_id,
            'centerNameAr' => $centerNameAr,
            'centerNameEn' => $centerNameEn,
            'centerAvatar' => $centerAvatar,
            'centerPhone' => $profile?->phone ?: ($centerUser?->phone ?? ''),
            'centerAddress' => $profile?->address_ar ?? '',
            'isVerifiedCenter' => (bool) ($profile?->is_verified_center ?? false),
            'branchNameAr' => $record->branch?->name_ar ?? 'الفرع الرئيسي',
            'subjectAr' => $record->subject_ar,
            'settlementRate' => (float) $record->settlement_rate,
            'settlementPercent' => (int) round(((float) $record->settlement_rate) * 100),
            'status' => $record->status,
            'inviteSentAt' => ($record->invite_sent_at ?? $record->created_at)?->toIso8601String(),
            'activatedAt' => $record->activated_at?->toIso8601String(),
        ];
    }
}
