<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EstructuraRepositoryInterface;

final readonly class GetEstructuraAction
{
    public function __construct(private EstructuraRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $edificioId): array
    {
        return $this->repository->getForEdificio($edificioId);
    }
}
