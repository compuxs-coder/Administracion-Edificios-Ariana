<?php

namespace Src\Edificio\Application\Actions;

use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Domain\Exceptions\EdificioNotFoundException;

final readonly class GetEdificioAction
{
    public function __construct(private EdificioRepositoryInterface $repository) {}

    public function execute(string $id): Edificio
    {
        return $this->repository->find($id)
            ?? throw new EdificioNotFoundException($id);
    }
}
