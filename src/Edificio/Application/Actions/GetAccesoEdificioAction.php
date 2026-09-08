<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\AccesoEdificioRepositoryInterface;

final readonly class GetAccesoEdificioAction
{
    public function __construct(private AccesoEdificioRepositoryInterface $repository) {}

    public function execute(string $edificioId): array
    {
        return $this->repository->managementData($edificioId);
    }
}
