<?php

namespace Src\Factura\Domain\Contracts;

use Src\Factura\Domain\Entities\Factura;

interface FacturaRepositoryInterface
{
    /** @return list<Factura> */
    public function all(): array;

    public function find(string $id): ?Factura;

    public function save(Factura $factura): Factura;

    public function delete(Factura $factura): void;
}
