<?php

namespace Src\Factura\Application\Actions;

use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Entities\Factura;
use Src\Factura\Domain\Exceptions\FacturaNotFoundException;

final readonly class GetFacturaAction
{
    public function __construct(private FacturaRepositoryInterface $repository) {}

    public function execute(string $id): Factura
    {
        return $this->repository->find($id)
            ?? throw new FacturaNotFoundException($id);
    }
}
