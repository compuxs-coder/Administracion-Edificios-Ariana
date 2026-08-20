<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;

final readonly class ListEdificiosAction
{
    public function __construct(private EdificioRepositoryInterface $repository) {}

    /**
     * @return array{
     *     items: list<\Src\Edificio\Domain\Entities\Edificio>,
     *     total: int,
     *     currentPage: int,
     *     lastPage: int,
     *     perPage: int
     * }
     */
    public function execute(
        string $userId,
        ?string $search,
        int $page = 1,
        int $perPage = 10,
    ): array {
        return $this->repository->paginateAssignedTo($userId, $search, $page, $perPage);
    }
}
