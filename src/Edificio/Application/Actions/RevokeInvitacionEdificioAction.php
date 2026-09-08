<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class RevokeInvitacionEdificioAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $actorId, string $edificioId, string $invitationId): void
    {
        $this->repository->revokeInvitation($actorId, $edificioId, $invitationId);
    }
}
