<?php

namespace Src\Gobierno\Application\Actions;

use Src\Gobierno\Domain\Contracts\AuditoriaRepositoryInterface;

final readonly class GetCentroAuditoriaAction
{
    public function __construct(private AuditoriaRepositoryInterface $repository) {}

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function execute(string $userId, array $filters): array
    {
        $events = $this->repository->paginateForUser($userId, $filters);

        return [
            ...$this->repository->options($userId, $events['selectedBuildingIds']),
            'eventos' => $events,
        ];
    }
}
