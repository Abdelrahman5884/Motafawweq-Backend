<?php

namespace App\Repositories\Contracts;

use App\Models\CourseCertificate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CourseCertificateRepositoryInterface extends BaseRepositoryInterface
{
    public function getForTeacher(int $teacherId, array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findByCertNumber(string $certNumber): ?CourseCertificate;

    public function findByUuidForTeacher(string $uuid, int $teacherId): ?CourseCertificate;
}

