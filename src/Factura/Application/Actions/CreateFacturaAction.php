<?php

namespace Src\Factura\Application\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Entities\Factura;

final readonly class CreateFacturaAction
{
    public function __construct(private FacturaRepositoryInterface $repository) {}

    /** @param array<string, string> $data */
    public function execute(array $data): Factura
    {
        $factura = new Factura(
            id: (string) Str::uuid(),
            name: $data['name'],
            ruc: $data['ruc'],
            email: $data['email'],
            phone: $data['phone'],
            address: $data['address'],
            city: $data['city'],
            country: $data['country'],
            status: $data['status'],
            createdAt: new DateTimeImmutable,
        );

        return $this->repository->save($factura);
    }
}
