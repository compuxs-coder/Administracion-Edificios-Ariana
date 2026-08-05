<?php

namespace Src\Factura\Application\Actions;

use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Entities\Factura;

final readonly class ListFacturasAction
{
    public function __construct(private FacturaRepositoryInterface $repository) {}

    /** @return list<Factura> */
    public function execute(): array
    {
        return $this->repository->all();
    }
}
