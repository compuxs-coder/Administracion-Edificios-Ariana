<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class UpdateMemberRolesAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    /** @param list<string> $roles */
    public function execute(string $actorId, string $edificioId, string $memberId, array $roles): void
    {
        $this->repository->updateMemberRoles($actorId, $edificioId, $memberId, $roles);
    }
}
