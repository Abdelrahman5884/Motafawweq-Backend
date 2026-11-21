<?php

namespace App\Http\Resources\V1;

use App\Enums\StudentPlanTier;
use App\Enums\UserRole;
use App\Http\Resources\BaseJsonResource;
use App\Services\V1\Student\StudentBillingService;
use Illuminate\Http\Request;

class UserResource extends BaseJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $studentPlan = $this->role === UserRole::STUDENT
            ? app(StudentBillingService::class)->resolveActivePlanTier($this->resource)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->formatDate($this->email_verified_at),
            'role' => $this->role?->value,
            'role_label' => $this->role?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'student_code' => $this->when($this->role === UserRole::STUDENT || $this->student_code !== null, $this->student_code),
            'plan_tier' => $this->when($studentPlan instanceof StudentPlanTier, fn () => $studentPlan->value),
            'plan_name_ar' => $this->when($studentPlan instanceof StudentPlanTier, fn () => $studentPlan->labelAr()),
            'plan_name_en' => $this->when($studentPlan instanceof StudentPlanTier, fn () => $studentPlan->labelEn()),
            'unlocked_features' => $this->when($studentPlan instanceof StudentPlanTier, fn () => $studentPlan->unlockedFeatures()),
            'linked_children' => $this->when(
                $this->role === UserRole::PARENT,
                fn () => $this->children()->get(['users.id', 'users.name', 'users.email', 'users.student_code', 'users.avatar'])->map(fn ($child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'email' => $child->email,
                    'student_code' => $child->student_code,
                    'avatar' => $child->avatar,
                ])->values()
            ),
            'last_login_at' => $this->formatDate($this->last_login_at),
            'created_at' => $this->formatDate($this->created_at),
        ];
    }
}
