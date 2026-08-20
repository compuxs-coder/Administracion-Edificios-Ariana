<?php

namespace Src\Edificio\Domain\Contracts;

use Src\Edificio\Domain\Entities\Edificio;

interface EdificioRepositoryInterface
{
    /**
     * @return array{
     *     items: list<Edificio>,
     *     total: int,
     *     currentPage: int,
     *     lastPage: int,
     *     perPage: int
     * }
     */
    public function paginateAssignedTo(
        string $userId,
        ?string $search,
        int $page,
        int $perPage,
    ): array;

    public function find(string $id): ?Edificio;

    public function createForUser(Edificio $edificio, string $userId): Edificio;

    public function save(Edificio $edificio): Edificio;
}
