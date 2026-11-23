<?php

namespace App\Services\V1\Admin;

use App\Enums\AttendanceRecordMethod;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CenterProfile;
use App\Models\GroupAttendanceRecord;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminAttendanceService extends BaseService
{
    /**
     * Global Center Gate Attendance Overview & Paginated Logs across all centers (Zero N+1).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getGateOverviewAndLogs(array $filters = []): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $search = isset($filters['search']) ? trim((string) $filters['search']) : null;
        $centerId = isset($filters['center_id']) && is_numeric($filters['center_id']) ? (int) $filters['center_id'] : null;
        $centerSearch = isset($filters['center']) && ! is_numeric($filters['center']) ? trim((string) $filters['center']) : null;
        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : null;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : null;

        $baseQuery = GroupAttendanceRecord::query()
            ->with([
                'student:id,name,phone,student_code',
                'enrollment:id,uuid,student_name_override,student_barcode,payment_status,due_amount_egp',
                'group:id,uuid,center_id,center_branch_id,center_room_id,center_teacher_id,name_ar,subject_ar,center_name,hall_name,teacher_name_override',
                'group.centerTeacher:id,name_ar',
                'session:id,uuid,center_id,session_date',
            ])
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        if ($centerId !== null) {
            $baseQuery->whereHas('group', fn (Builder $q) => $q->where('center_id', $centerId));
        } elseif ($centerSearch !== null && $centerSearch !== '' && $centerSearch !== 'all') {
            $safeCenter = addcslashes($centerSearch, '%_\\');
            $baseQuery->whereHas('group', fn (Builder $q) => $q->where('center_name', 'like', "%{$safeCenter}%"));
        }

        if ($status === 'granted') {
            $baseQuery->whereIn('status', [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value]);
        } elseif ($status === 'denied') {
            $baseQuery->where(function (Builder $q): void {
                $q->where('status', AttendanceStatus::ABSENT->value)
                    ->orWhereHas('enrollment', fn (Builder $eq) => $eq->where('payment_status', 'due'));
            });
        }

        if ($dateFrom) {
            $baseQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $baseQuery->whereDate('created_at', '<=', $dateTo);
        }

        if ($search !== null && $search !== '') {
            $safe = addcslashes($search, '%_\\');
            $baseQuery->where(function (Builder $q) use ($safe): void {
                $q->whereHas('student', fn (Builder $sq) => $sq->where('name', 'like', "%{$safe}%")->orWhere('student_code', 'like', "%{$safe}%"))
                    ->orWhereHas('enrollment', fn (Builder $eq) => $eq->where('student_name_override', 'like', "%{$safe}%")->orWhere('student_barcode', 'like', "%{$safe}%"))
                    ->orWhereHas('group', fn (Builder $gq) => $gq->where('subject_ar', 'like', "%{$safe}%")->orWhere('name_ar', 'like', "%{$safe}%"));
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $baseQuery->paginate($perPage);

        // Today's global KPIs
        $today = now()->toDateString();
        $todayQuery = GroupAttendanceRecord::query()->whereDate('created_at', $today);
        $totalScansToday = (int) (clone $todayQuery)->count();
        $grantedToday = (int) (clone $todayQuery)->whereIn('status', [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value])->count();
        $deniedToday = (int) (clone $todayQuery)->where(function (Builder $q): void {
            $q->where('status', AttendanceStatus::ABSENT->value)
                ->orWhereHas('enrollment', fn (Builder $eq) => $eq->where('payment_status', 'due'));
        })->count();

        $authorizedRatePct = $totalScansToday > 0
            ? round(($grantedToday / $totalScansToday) * 100, 1)
            : 100.0;

        $connectedCenters = User::query()
            ->where('role', UserRole::CENTER->value)
            ->where('status', UserStatus::ACTIVE->value)
            ->with('centerProfile:id,user_id,name_ar,name_en')
            ->get(['id', 'name'])
            ->map(fn (User $c) => [
                'id' => $c->id,
                'nameAr' => $c->centerProfile?->name_ar ?: $c->name,
                'nameEn' => $c->centerProfile?->name_en ?: $c->name,
            ])
            ->values()
            ->all();

        $centerNamesById = CenterProfile::query()
            ->pluck('name_ar', 'user_id')
            ->all();

        $items = collect($paginator->items())
            ->map(fn (GroupAttendanceRecord $r) => $this->formatGateLog($r, $centerNamesById))
            ->values()
            ->all();

        return [
            'summary' => [
                'totalScansToday' => $totalScansToday,
                'authorizedEntriesToday' => $grantedToday,
                'authorizedRatePct' => $authorizedRatePct,
                'deniedOrUnpaidCount' => $deniedToday,
                'connectedCentersCount' => count($connectedCenters),
            ],
            'centers' => $connectedCenters,
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Export filtered gate attendance logs as a formula-safe UTF-8 CSV.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportGateLogsCsv(User $admin, array $filters = []): string
    {
        $filters['per_page'] = 100;
        $data = $this->getGateOverviewAndLogs($filters);

        $headers = ['الطالب', 'الباركود', 'السنتر', 'القاعة', 'المادة / المجموعة', 'وقت المسح', 'الحالة', 'السبب'];
        $escape = static function (mixed $val): string {
            $s = (string) ($val ?? '');
            if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
                $s = "'".$s;
            }

            return '"'.str_replace('"', '""', $s).'"';
        };

        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($data['items'] as $row) {
            $lines[] = implode(',', array_map($escape, [
                $row['studentNameAr'],
                $row['barcode'],
                $row['centerNameAr'],
                $row['hallAr'],
                $row['subjectAr'],
                $row['scanTime'],
                $row['status'],
                $row['reasonAr'],
            ]));
        }

        $this->logSecurityEvent('admin.attendance.exported_csv', [
            'admin_id' => $admin->id,
            'rows_count' => count($data['items']),
        ], 'warning');

        return "\xEF\xBB\xBF".implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $centerNamesById
     * @return array<string, mixed>
     */
    protected function formatGateLog(GroupAttendanceRecord $r, array $centerNamesById): array
    {
        $rawStatus = $r->status instanceof AttendanceStatus ? $r->status->value : (string) $r->status;
        $paymentStatus = $r->enrollment?->payment_status ?: 'paid';
        $dueAmt = (float) ($r->enrollment?->due_amount_egp ?? 0);

        $isGranted = in_array($rawStatus, [AttendanceStatus::PRESENT->value, AttendanceStatus::LATE->value], true)
            && $paymentStatus !== 'due';

        $studentName = $r->enrollment?->student_name_override ?: ($r->student?->name ?? 'طالب');
        $barcode = $r->enrollment?->student_barcode ?: ($r->student?->student_code ?: 'N/A');

        $centerName = ($r->group?->center_id && isset($centerNamesById[$r->group->center_id]))
            ? $centerNamesById[$r->group->center_id]
            : ($r->group?->center_name ?: 'سنتر غير محدد');

        $teacherLabel = $r->group?->centerTeacher?->name_ar ?: ($r->group?->teacher_name_override ?? '');
        $subjectDisplay = $r->group?->subject_ar ?: ($r->group?->name_ar ?? '');
        if ($teacherLabel !== '') {
            $subjectDisplay .= " - {$teacherLabel}";
        }

        if ($isGranted) {
            $reasonAr = $rawStatus === AttendanceStatus::LATE->value
                ? 'تم الدخول بتأخير طفيف (مؤكد)'
                : 'تم تأكيد الحضور ودفع الحصة';
            $reasonEn = $rawStatus === AttendanceStatus::LATE->value
                ? 'Access granted (Late check-in)'
                : 'Attendance confirmed and session paid';
        } elseif ($paymentStatus === 'due') {
            $reasonAr = "متأخرات غير مسددة ({$dueAmt} ج.م)";
            $reasonEn = "Unpaid tuition balance ({$dueAmt} EGP)";
        } else {
            $reasonAr = 'غياب / عدم تسجيل دخول مصرح';
            $reasonEn = 'Absent / Admission denied';
        }

        return [
            'id' => $r->uuid,
            'studentName' => $studentName,
            'studentNameAr' => $studentName,
            'studentNameEn' => $studentName,
            'barcode' => $barcode,
            'centerId' => $r->group?->center_id,
            'centerName' => $centerName,
            'centerNameAr' => $centerName,
            'centerNameEn' => $centerName,
            'hall' => $r->group?->hall_name ?: 'القاعة الرئيسية',
            'hallAr' => $r->group?->hall_name ?: 'القاعة الرئيسية',
            'hallEn' => $r->group?->hall_name ?: 'Main Hall',
            'subject' => $subjectDisplay,
            'subjectAr' => $subjectDisplay,
            'subjectEn' => $subjectDisplay,
            'method' => $r->recorded_method instanceof AttendanceRecordMethod ? $r->recorded_method->value : (string) $r->recorded_method,
            'scanTime' => ($r->scanned_at ?? $r->created_at)?->format('Y-m-d H:i'),
            'status' => $isGranted ? 'granted' : 'denied',
            'attendanceStatus' => $rawStatus,
            'paymentStatus' => $paymentStatus,
            'reason' => $reasonAr,
            'reasonAr' => $reasonAr,
            'reasonEn' => $reasonEn,
        ];
    }
}
