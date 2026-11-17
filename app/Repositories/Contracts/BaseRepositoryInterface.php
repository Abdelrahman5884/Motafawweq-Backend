<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface BaseRepositoryInterface
{
    /**
     * Get all records.
     */
    public function all(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Get paginated records.
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator;

    /**
     * Find record by ID.
     */
    public function findById(int|string $id, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Find record or fail.
     */
    public function findOrFail(int|string $id, array $columns = ['*'], array $relations = []): Model;

    /**
     * Find first record by specific criteria.
     */
    public function findOneBy(array $criteria, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Create a new record.
     */
    public function create(array $attributes): Model;

    /**
     * Create a record bypassing $fillable (service-layer use only, for guarded columns).
     */
    public function forceCreate(array $attributes): Model;

    /**
     * Update a model bypassing $fillable (service-layer use only, for guarded columns).
     */
    public function forceUpdate(Model $model, array $attributes): Model;

    /**
     * Update an existing record.
     */
    public function update(int|string $id, array $attributes): Model;

    /**
     * Delete a record by ID.
     */
    public function delete(int|string $id): bool;
}
