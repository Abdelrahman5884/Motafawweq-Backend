<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

interface BaseRepositoryInterface
{
    /**
     * Get all records.
     *
     * @param  array  $columns
     * @param  array  $relations
     * @return Collection
     */
    public function all(array $columns = ['*'], array $relations = []): Collection;

    /**
     * Get paginated records.
     *
     * @param  int  $perPage
     * @param  array  $columns
     * @param  array  $relations
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator;

    /**
     * Find record by ID.
     *
     * @param  int|string  $id
     * @param  array  $columns
     * @param  array  $relations
     * @return Model|null
     */
    public function findById(int|string $id, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Find record or fail.
     *
     * @param  int|string  $id
     * @param  array  $columns
     * @param  array  $relations
     * @return Model
     */
    public function findOrFail(int|string $id, array $columns = ['*'], array $relations = []): Model;

    /**
     * Find first record by specific criteria.
     *
     * @param  array  $criteria
     * @param  array  $columns
     * @param  array  $relations
     * @return Model|null
     */
    public function findOneBy(array $criteria, array $columns = ['*'], array $relations = []): ?Model;

    /**
     * Create a new record.
     *
     * @param  array  $attributes
     * @return Model
     */
    public function create(array $attributes): Model;

    /**
     * Update an existing record.
     *
     * @param  int|string  $id
     * @param  array  $attributes
     * @return Model
     */
    public function update(int|string $id, array $attributes): Model;

    /**
     * Delete a record by ID.
     *
     * @param  int|string  $id
     * @return bool
     */
    public function delete(int|string $id): bool;
}

