<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class AcceptInvitacionEdificioAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $token, string $userId): array
    {
        return $this->repository->acceptInvitation($token, $userId);
    }
}
