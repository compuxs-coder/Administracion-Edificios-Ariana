<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class RevokeEdificioMemberAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $actorId, string $edificioId, string $memberId): void
    {
        $this->repository->revokeMembership($actorId, $edificioId, $memberId);
    }
}
