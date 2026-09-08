<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\DepartamentoRepositoryInterface;

final readonly class GetDepartamentoOptionsAction
{
    public function __construct(private DepartamentoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, bool $forManagement = false): array
    {
        return $this->repository->formOptionsForUser($userId, $forManagement);
    }
}
