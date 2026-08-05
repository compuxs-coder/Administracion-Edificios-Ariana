<?php

namespace Src\Factura\Application\Actions;

use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Exceptions\FacturaNotFoundException;

final readonly class DeleteFacturaAction
{
    public function __construct(private FacturaRepositoryInterface $repository) {}

    public function execute(string $id): void
    {
        $factura = $this->repository->find($id)
            ?? throw new FacturaNotFoundException($id);

        $this->repository->delete($factura);
    }
}
