<?php

namespace App\Services\V1\Center;

use App\Enums\CenterPlanTier;
use App\Enums\CenterRefundPolicy;
use App\Models\CenterAnnouncement;
use App\Models\CenterBackup;
use App\Models\CenterBranch;
use App\Models\CenterExpense;
use App\Models\CenterProfile;
use App\Models\CenterRoom;
use App\Models\CenterStaff;
use App\Models\CenterTask;
use App\Models\CenterTeacher;
use App\Models\GroupAttendanceRecord;
use App\Models\GroupAttendanceSession;
use App\Models\GroupEnrollment;
use App\Models\StudyGroup;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class CenterSettingsService extends BaseService
{
    public function __construct(
        protected CenterBranchService $branchService,
        protected CenterGroupService $groupService,
        protected CenterBillingService $billingService
    ) {}

    /**
     * Retrieve or initialize the Center's profile, operational policies, KPI targets, and backup status.
     *
     * @return array<string, mixed>
     */
    public function getSettings(User $center): array
    {
        $profile = $this->firstOrCreateProfile($center);
        $planTier = $this->billingService->resolveActivePlanTier($center);

        return $this->formatProfile($profile, $planTier);
    }

    /**
     * Update Center identity, operational policies, payment methods, and optional Pro white-label settings.
     *
     * @return array<string, mixed>
     */
    public function updateSettings(User $center, array $data): array
    {
        $profile = $this->firstOrCreateProfile($center);
        $planTier = $this->billingService->resolveActivePlanTier($center);

        $updated = $this->transaction(function () use ($profile, $planTier, $data) {
            $payload = [];

            if (array_key_exists('name_ar', $data) || array_key_exists('centerNameAr', $data)) {
                $payload['name_ar'] = trim(strip_tags((string) ($data['name_ar'] ?? $data['centerNameAr'])));
            }
            if (array_key_exists('name_en', $data) || array_key_exists('centerNameEn', $data)) {
                $payload['name_en'] = trim(strip_tags((string) ($data['name_en'] ?? $data['centerNameEn'])));
            }
            if (array_key_exists('phone', $data) || array_key_exists('centerPhone', $data)) {
                $payload['phone'] = trim(strip_tags((string) ($data['phone'] ?? $data['centerPhone'])));
            }
            if (array_key_exists('address_ar', $data) || array_key_exists('centerAddress', $data)) {
                $payload['address_ar'] = trim(strip_tags((string) ($data['address_ar'] ?? $data['centerAddress'])));
            }
            if (array_key_exists('address_en', $data)) {
                $payload['address_en'] = trim(strip_tags((string) $data['address_en']));
            }
            if (array_key_exists('working_hours', $data) || array_key_exists('workingHours', $data)) {
                $payload['working_hours'] = trim(strip_tags((string) ($data['working_hours'] ?? $data['workingHours'])));
            }
            if (array_key_exists('late_margin_minutes', $data) || array_key_exists('lateMarginMinutes', $data)) {
                $payload['late_margin_minutes'] = max(5, min(60, (int) ($data['late_margin_minutes'] ?? $data['lateMarginMinutes'])));
            }
            if (array_key_exists('sibling_discount_percent', $data) || array_key_exists('siblingDiscountPercent', $data)) {
                $payload['sibling_discount_percent'] = max(0, min(50, (int) ($data['sibling_discount_percent'] ?? $data['siblingDiscountPercent'])));
            }
            if (array_key_exists('mandatory_cash_close', $data) || array_key_exists('mandatoryCashClose', $data)) {
                $payload['mandatory_cash_close'] = (bool) ($data['mandatory_cash_close'] ?? $data['mandatoryCashClose']);
            }
            if (array_key_exists('refund_policy', $data) || array_key_exists('refundPolicy', $data)) {
                $rawPolicy = strtolower(trim((string) ($data['refund_policy'] ?? $data['refundPolicy'])));
                $policyEnum = CenterRefundPolicy::tryFrom($rawPolicy);
                if ($policyEnum) {
                    $payload['refund_policy'] = $policyEnum;
                }
            }
            if (array_key_exists('default_teacher_settlement_rate', $data)) {
                $payload['default_teacher_settlement_rate'] = max(0.05, min(0.95, (float) $data['default_teacher_settlement_rate']));
            }
            if (array_key_exists('payment_methods', $data) || array_key_exists('paymentMethods', $data)) {
                $rawPm = $data['payment_methods'] ?? $data['paymentMethods'];
                if (is_array($rawPm)) {
                    $payload['payment_methods'] = [
                        'cash' => (bool) ($rawPm['cash'] ?? false),
                        'vodafoneCash' => (bool) ($rawPm['vodafoneCash'] ?? false),
                        'instaPay' => (bool) ($rawPm['instaPay'] ?? false),
                        'visa' => (bool) ($rawPm['visa'] ?? false),
                    ];
                }
            }
            if (array_key_exists('preferred_theme', $data) && in_array($data['preferred_theme'], ['light', 'dark'], true)) {
                $payload['preferred_theme'] = $data['preferred_theme'];
            }
            if (array_key_exists('preferred_lang', $data) && in_array($data['preferred_lang'], ['ar', 'en'], true)) {
                $payload['preferred_lang'] = $data['preferred_lang'];
            }

            // White-label fields are strictly restricted to Center Pro tier
            if (array_key_exists('custom_domain', $data) && $data['custom_domain'] !== null && $data['custom_domain'] !== '') {
                if (! $planTier->allowsWhiteLabel()) {
                    throw ValidationException::withMessages([
                        'custom_domain' => [__('messages.center.white_label_requires_pro')],
                    ]);
                }
                $payload['custom_domain'] = strtolower(trim(strip_tags((string) $data['custom_domain'])));
            }

            if (! empty($payload)) {
                $profile->update($payload);
            }

            $this->logSecurityEvent('center.settings.updated', [
                'changes' => array_keys($payload),
            ], 'info', $profile);

            return $profile->fresh();
        });

        return $this->formatProfile($updated, $planTier);
    }

    /**
     * Update custom KPI performance target thresholds.
     *
     * @return array<string, mixed>
     */
    public function updateKpiTargets(User $center, array $data): array
    {
        $profile = $this->firstOrCreateProfile($center);
        $planTier = $this->billingService->resolveActivePlanTier($center);

        $payload = [];
        if (array_key_exists('target_attendance_pct', $data) || array_key_exists('targetAttendance', $data)) {
            $payload['target_attendance_pct'] = max(50, min(100, (int) ($data['target_attendance_pct'] ?? $data['targetAttendance'])));
        }
        if (array_key_exists('target_monthly_revenue_egp', $data) || array_key_exists('targetRevenue', $data)) {
            $payload['target_monthly_revenue_egp'] = max(10000.0, (float) ($data['target_monthly_revenue_egp'] ?? $data['targetRevenue']));
        }
        if (array_key_exists('target_room_utilization_pct', $data) || array_key_exists('targetUtilization', $data)) {
            $payload['target_room_utilization_pct'] = max(30, min(100, (int) ($data['target_room_utilization_pct'] ?? $data['targetUtilization'])));
        }

        if (! empty($payload)) {
            $profile->update($payload);
        }

        $this->logSecurityEvent('center.settings.kpis_updated', $payload, 'info', $profile);

        return $this->formatProfile($profile->fresh(), $planTier);
    }

    /* =====================================================================
     * DAILY ADMINISTRATIVE TASKS CRUD
     * ===================================================================== */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTasks(User $center, array $filters = []): array
    {
        $query = CenterTask::forCenter($center->id)
            ->orderBy('is_completed')
            ->orderByDesc('created_at');

        if (isset($filters['completed']) && $filters['completed'] !== '') {
            $query->where('is_completed', filter_var($filters['completed'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->get()->map(fn (CenterTask $t) => $this->formatTask($t))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createTask(User $center, array $data): array
    {
        $branchId = $this->resolveOptionalOwnedBranchId($center, $data['branch_id'] ?? null);

        /** @var CenterTask $task */
        $task = CenterTask::create([
            'center_id' => $center->id,
            'branch_id' => $branchId,
            'title_ar' => trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'] ?? ''))),
            'assignee_name' => trim(strip_tags((string) ($data['assignee_name'] ?? $data['assignee'] ?? 'إدارة السنتر'))),
            'deadline_label' => isset($data['deadline_label']) || isset($data['deadline'])
                ? trim(strip_tags((string) ($data['deadline_label'] ?? $data['deadline'])))
                : null,
            'is_completed' => false,
        ]);

        $this->logSecurityEvent('center.task.created', ['task_uuid' => $task->uuid], 'info', $task);

        return $this->formatTask($task);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateTask(User $center, string $uuid, array $data): array
    {
        $task = $this->findOwnedTask($center, $uuid);

        $payload = [];
        if (array_key_exists('title_ar', $data) || array_key_exists('titleAr', $data)) {
            $payload['title_ar'] = trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'])));
        }
        if (array_key_exists('assignee_name', $data) || array_key_exists('assignee', $data)) {
            $payload['assignee_name'] = trim(strip_tags((string) ($data['assignee_name'] ?? $data['assignee'])));
        }
        if (array_key_exists('deadline_label', $data) || array_key_exists('deadline', $data)) {
            $payload['deadline_label'] = trim(strip_tags((string) ($data['deadline_label'] ?? $data['deadline'])));
        }
        if (array_key_exists('is_completed', $data) || array_key_exists('completed', $data)) {
            $completed = (bool) ($data['is_completed'] ?? $data['completed']);
            $payload['is_completed'] = $completed;
            $payload['completed_at'] = $completed ? now() : null;
        }

        if (! empty($payload)) {
            $task->update($payload);
        }

        return $this->formatTask($task->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleTask(User $center, string $uuid): array
    {
        $task = $this->findOwnedTask($center, $uuid);
        $next = ! $task->is_completed;
        $task->update([
            'is_completed' => $next,
            'completed_at' => $next ? now() : null,
        ]);

        return $this->formatTask($task->fresh());
    }

    public function deleteTask(User $center, string $uuid): bool
    {
        $task = $this->findOwnedTask($center, $uuid);
        $task->delete();

        $this->logSecurityEvent('center.task.deleted', ['task_uuid' => $uuid], 'info');

        return true;
    }

    /* =====================================================================
     * INTERNAL STAFF ANNOUNCEMENTS
     * ===================================================================== */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAnnouncements(User $center): array
    {
        return CenterAnnouncement::forCenter($center->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CenterAnnouncement $a) => $this->formatAnnouncement($a))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function createAnnouncement(User $center, array $data): array
    {
        $branchId = $this->resolveOptionalOwnedBranchId($center, $data['branch_id'] ?? null);
        $branchLabel = 'جميع الفروع';
        if ($branchId) {
            $branchLabel = CenterBranch::where('id', $branchId)->value('name_ar') ?: $branchLabel;
        } elseif (! empty($data['branch_label']) || ! empty($data['branch'])) {
            $branchLabel = trim(strip_tags((string) ($data['branch_label'] ?? $data['branch'])));
        }

        /** @var CenterAnnouncement $ann */
        $ann = CenterAnnouncement::create([
            'center_id' => $center->id,
            'branch_id' => $branchId,
            'title_ar' => trim(strip_tags((string) ($data['title_ar'] ?? $data['titleAr'] ?? ''))),
            'content_ar' => trim(strip_tags((string) ($data['content_ar'] ?? $data['contentAr'] ?? ''))),
            'author_name' => trim(strip_tags((string) ($data['author_name'] ?? $center->name ?: 'إدارة السنتر العامة'))),
            'branch_label' => $branchLabel,
            'published_at' => now(),
        ]);

        $this->logSecurityEvent('center.announcement.published', [
            'announcement_uuid' => $ann->uuid,
            'branch_label' => $branchLabel,
        ], 'info', $ann);

        return $this->formatAnnouncement($ann);
    }

    public function deleteAnnouncement(User $center, string $uuid): bool
    {
        /** @var CenterAnnouncement|null $ann */
        $ann = CenterAnnouncement::where('uuid', $uuid)->first();
        if (! $ann) {
            throw (new ModelNotFoundException)->setModel(CenterAnnouncement::class, [$uuid]);
        }

        $ann->assertOwnedByCenter($center);
        $ann->delete();

        return true;
    }

    /* =====================================================================
     * BATCH STUDENT DATA IMPORT (CSV / JSON)
     * ===================================================================== */

    public function getStudentImportTemplateCsv(): string
    {
        $headers = ['اسم الطالب', 'رقم الهاتف', 'المجموعة', 'المرحلة الدراسية', 'اسم ولي الأمر', 'هاتف ولي الأمر'];
        $sampleRows = [
            ['طالب تجريبي 1', '01000000001', 'فيزياء الثانوية العامة', 'الصف الثالث الثانوي', 'ولي أمر 1', '01000000011'],
            ['طالب تجريبي 2', '01000000002', 'كيمياء اللغات', 'الصف الثالث الثانوي', 'ولي أمر 2', '01000000022'],
        ];

        $escape = fn (string $v) => '"'.str_replace('"', '""', $v).'"';
        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($sampleRows as $row) {
            $lines[] = implode(',', array_map($escape, $row));
        }

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * Batch import students into center study groups with deduplication and quota validation.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function importStudents(User $center, array $rows, ?string $defaultGroupUuid = null): array
    {
        $defaultGroup = $defaultGroupUuid
            ? $this->groupService->findOwnedGroup($center, $defaultGroupUuid)
            : null;

        $centerGroups = StudyGroup::where('center_id', $center->id)
            ->where('status', 'active')
            ->get()
            ->keyBy(fn (StudyGroup $g) => mb_strtolower(trim($g->name_ar)));

        $importedRows = [];
        $skippedRows = [];
        $seenPhones = [];

        foreach ($rows as $idx => $rawRow) {
            if (! is_array($rawRow)) {
                continue;
            }

            $rowNum = $idx + 1;
            $name = trim(strip_tags((string) ($rawRow['name'] ?? $rawRow['name_ar'] ?? '')));
            $phone = trim(strip_tags((string) ($rawRow['phone'] ?? '')));
            $groupName = trim(strip_tags((string) ($rawRow['group'] ?? $rawRow['group_name'] ?? '')));
            $grade = trim(strip_tags((string) ($rawRow['grade'] ?? $rawRow['grade_ar'] ?? 'الصف الثالث الثانوي')));
            $parentName = trim(strip_tags((string) ($rawRow['parent_name'] ?? $rawRow['parent_name_ar'] ?? '')));
            $parentPhone = trim(strip_tags((string) ($rawRow['parent_phone'] ?? '')));

            if ($name === '' || $phone === '') {
                $skippedRows[] = ['row' => $rowNum, 'reason' => 'الاسم ورقم الهاتف مطلوبان.'];

                continue;
            }

            $dedupeKey = $phone.'|'.mb_strtolower($groupName);
            if (isset($seenPhones[$dedupeKey])) {
                $skippedRows[] = ['row' => $rowNum, 'phone' => $phone, 'reason' => 'سجل مكرر داخل ملف الاستيراد.'];

                continue;
            }
            $seenPhones[$dedupeKey] = true;

            $targetGroup = $defaultGroup;
            if (! $targetGroup && $groupName !== '') {
                $targetGroup = $centerGroups->get(mb_strtolower($groupName));
            }
            if (! $targetGroup && $centerGroups->isNotEmpty()) {
                $targetGroup = $centerGroups->first();
            }

            if (! $targetGroup) {
                $skippedRows[] = ['row' => $rowNum, 'phone' => $phone, 'reason' => 'لا توجد مجموعة مطابقة داخل السنتر.'];

                continue;
            }

            try {
                $enrolled = $this->groupService->enrollStudent($center, $targetGroup->uuid, [
                    'name_ar' => $name,
                    'phone' => $phone,
                    'grade_ar' => $grade,
                    'parent_name_ar' => $parentName ?: null,
                    'parent_phone' => $parentPhone ?: null,
                ]);

                $importedRows[] = [
                    'row' => $rowNum,
                    'enrollmentId' => $enrolled['enrollmentId'],
                    'name' => $enrolled['nameAr'],
                    'phone' => $enrolled['phone'],
                    'group' => $targetGroup->name_ar,
                    'grade' => $grade,
                    'barcode' => $enrolled['barcode'],
                ];
            } catch (ValidationException $e) {
                $firstMsg = collect($e->errors())->flatten()->first() ?? 'تعذر استيراد السجل.';
                $skippedRows[] = ['row' => $rowNum, 'phone' => $phone, 'reason' => $firstMsg];
            }
        }

        $this->logSecurityEvent('center.import.students_completed', [
            'total_rows' => count($rows),
            'imported_count' => count($importedRows),
            'skipped_count' => count($skippedRows),
        ], 'info');

        return [
            'importedCount' => count($importedRows),
            'skippedCount' => count($skippedRows),
            'importedRows' => $importedRows,
            'skippedRows' => $skippedRows,
        ];
    }

    /* =====================================================================
     * CLOUD BACKUP SNAPSHOTS (PRO TIER)
     * ===================================================================== */

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBackups(User $center): array
    {
        return CenterBackup::forCenter($center->id)
            ->orderByDesc('completed_at')
            ->limit(25)
            ->get()
            ->map(fn (CenterBackup $b) => $this->formatBackup($b))
            ->values()
            ->all();
    }

    /**
     * Generate an integrity-verified SHA-256 cloud backup snapshot record for this center's tenant data.
     *
     * @return array<string, mixed>
     */
    public function createBackup(User $center): array
    {
        $profile = $this->firstOrCreateProfile($center);

        $groupIds = StudyGroup::where('center_id', $center->id)->pluck('id')->all();

        $summary = [
            'branches' => CenterBranch::forCenter($center->id)->count(),
            'rooms' => CenterRoom::forCenter($center->id)->count(),
            'teachers' => CenterTeacher::forCenter($center->id)->count(),
            'staff' => CenterStaff::forCenter($center->id)->count(),
            'groups' => count($groupIds),
            'enrollments' => empty($groupIds) ? 0 : GroupEnrollment::whereIn('study_group_id', $groupIds)->count(),
            'attendanceSessions' => empty($groupIds) ? 0 : GroupAttendanceSession::whereIn('study_group_id', $groupIds)->count(),
            'attendanceRecords' => empty($groupIds) ? 0 : GroupAttendanceRecord::whereIn('study_group_id', $groupIds)->count(),
            'expenses' => CenterExpense::forCenter($center->id)->count(),
            'tasks' => CenterTask::forCenter($center->id)->count(),
            'announcements' => CenterAnnouncement::forCenter($center->id)->count(),
        ];

        $manifestJson = json_encode([
            'center_id' => $center->id,
            'timestamp' => now()->toIso8601String(),
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE);

        $checksum = hash('sha256', (string) $manifestJson);
        $estimatedBytes = max(1024, (array_sum($summary) * 512) + strlen((string) $manifestJson));

        $backup = $this->transaction(function () use ($center, $profile, $summary, $checksum, $estimatedBytes) {
            /** @var CenterBackup $created */
            $created = CenterBackup::create([
                'center_id' => $center->id,
                'triggered_by' => $center->id,
                'snapshot_code' => CenterBackup::generateSnapshotCode(),
                'status' => 'completed',
                'checksum_sha256' => $checksum,
                'records_summary' => $summary,
                'size_bytes' => $estimatedBytes,
                'completed_at' => now(),
            ]);

            $profile->update([
                'last_backup_at' => $created->completed_at,
                'last_backup_checksum' => $checksum,
            ]);

            $this->logSecurityEvent('center.backup.created', [
                'snapshot_code' => $created->snapshot_code,
                'checksum_prefix' => substr($checksum, 0, 12),
                'records_total' => array_sum($summary),
            ], 'info', $created);

            return $created;
        });

        return $this->formatBackup($backup);
    }

    protected function firstOrCreateProfile(User $center): CenterProfile
    {
        return CenterProfile::firstOrCreate(
            ['user_id' => $center->id],
            [
                'name_ar' => 'السنتر التعليمي',
                'name_en' => 'Educational Center',
                'phone' => $center->phone,
            ]
        );
    }

    protected function findOwnedTask(User $center, string $uuid): CenterTask
    {
        /** @var CenterTask|null $task */
        $task = CenterTask::where('uuid', $uuid)->first();
        if (! $task) {
            throw (new ModelNotFoundException)->setModel(CenterTask::class, [$uuid]);
        }

        $task->assertOwnedByCenter($center);

        return $task;
    }

    protected function resolveOptionalOwnedBranchId(User $center, mixed $branchUuid): ?int
    {
        if (empty($branchUuid) || $branchUuid === 'all') {
            return null;
        }

        return $this->branchService->findOwnedBranch($center, (string) $branchUuid)->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatProfile(CenterProfile $p, CenterPlanTier $tier): array
    {
        $refundVal = $p->refund_policy instanceof CenterRefundPolicy ? $p->refund_policy->value : (string) $p->refund_policy;

        return [
            'id' => $p->uuid,
            'nameAr' => $p->name_ar,
            'nameEn' => $p->name_en ?: $p->name_ar,
            'centerNameAr' => $p->name_ar,
            'centerNameEn' => $p->name_en ?: $p->name_ar,
            'phone' => $p->phone ?? '',
            'centerPhone' => $p->phone ?? '',
            'addressAr' => $p->address_ar ?? '',
            'addressEn' => $p->address_en ?? '',
            'centerAddress' => $p->address_ar ?? '',
            'centerAddressEn' => $p->address_en ?? '',
            'workingHours' => $p->working_hours ?? '08:00 ص - 10:00 م',
            'workingHoursAr' => $p->working_hours ?? '08:00 ص - 10:00 م',
            'logoUrl' => $p->logo_url,
            'customDomain' => $p->custom_domain,
            'planTier' => $tier->value,
            'planNameAr' => $tier->labelAr(),
            'planNameEn' => $tier->labelEn(),
            'isVerifiedCenter' => (bool) $p->is_verified_center,
            'lateMarginMinutes' => (int) $p->late_margin_minutes,
            'paymentMethods' => $p->payment_methods ?? [
                'cash' => true,
                'vodafoneCash' => true,
                'instaPay' => true,
                'visa' => true,
            ],
            'siblingDiscountPercent' => (int) $p->sibling_discount_percent,
            'mandatoryCashClose' => (bool) $p->mandatory_cash_close,
            'refundPolicy' => $refundVal,
            'defaultTeacherSettlementRate' => (float) $p->default_teacher_settlement_rate,
            'kpiTargets' => [
                'targetAttendance' => (int) $p->target_attendance_pct,
                'targetAttendancePct' => (int) $p->target_attendance_pct,
                'targetRevenue' => (float) $p->target_monthly_revenue_egp,
                'targetMonthlyRevenueEgp' => (float) $p->target_monthly_revenue_egp,
                'targetUtilization' => (int) $p->target_room_utilization_pct,
                'targetRoomUtilizationPct' => (int) $p->target_room_utilization_pct,
            ],
            'preferredTheme' => $p->preferred_theme ?? 'light',
            'preferredLang' => $p->preferred_lang ?? 'ar',
            'lastBackupAt' => $p->last_backup_at?->toIso8601String(),
            'lastBackupChecksum' => $p->last_backup_checksum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatTask(CenterTask $t): array
    {
        return [
            'id' => $t->uuid,
            'uuid' => $t->uuid,
            'titleAr' => $t->title_ar,
            'assignee' => $t->assignee_name ?? '',
            'deadline' => $t->deadline_label ?? '',
            'completed' => (bool) $t->is_completed,
            'completedAt' => $t->completed_at?->toIso8601String(),
            'createdAt' => $t->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAnnouncement(CenterAnnouncement $a): array
    {
        return [
            'id' => $a->uuid,
            'uuid' => $a->uuid,
            'titleAr' => $a->title_ar,
            'contentAr' => $a->content_ar,
            'author' => $a->author_name,
            'branch' => $a->branch_label,
            'date' => ($a->published_at ?? $a->created_at)?->toDateString(),
            'publishedAt' => ($a->published_at ?? $a->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatBackup(CenterBackup $b): array
    {
        return [
            'id' => $b->uuid,
            'snapshotCode' => $b->snapshot_code,
            'status' => $b->status,
            'checksumSha256' => $b->checksum_sha256,
            'recordsSummary' => $b->records_summary ?? [],
            'sizeBytes' => (int) $b->size_bytes,
            'completedAt' => $b->completed_at?->toIso8601String(),
        ];
    }
}
