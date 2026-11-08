<?php

namespace App\Repositories\Contracts;

use App\Models\StudentDirectPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StudentDirectPaymentRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findByUuidForTeacher(string $uuid, int $teacherId): ?StudentDirectPayment;

    public function getTeacherFinancialKPIs(int $teacherId): array;
}

