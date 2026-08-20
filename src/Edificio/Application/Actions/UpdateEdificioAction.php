<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Domain\Exceptions\EdificioNotFoundException;

final readonly class UpdateEdificioAction
{
    public function __construct(private EdificioRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(string $id, array $data): Edificio
    {
        $edificio = $this->repository->find($id)
            ?? throw new EdificioNotFoundException($id);

        $edificio->actualizar($data);

        return $this->repository->save($edificio);
    }
}
