<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class GetInvitacionEdificioAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $token, string $userId): array
    {
        return $this->repository->invitation($token, $userId);
    }
}
