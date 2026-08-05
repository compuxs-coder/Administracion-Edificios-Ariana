<?php

namespace Src\Factura\Application\Actions;

use Src\Factura\Domain\Contracts\FacturaRepositoryInterface;
use Src\Factura\Domain\Entities\Factura;
use Src\Factura\Domain\Exceptions\FacturaNotFoundException;

final readonly class UpdateFacturaAction
{
    public function __construct(private FacturaRepositoryInterface $repository) {}

    /** @param array<string, string> $data */
    public function execute(string $id, array $data): Factura
    {
        $factura = $this->repository->find($id)
            ?? throw new FacturaNotFoundException($id);

        $factura->update(
            name: $data['name'] ?? $factura->name(),
            ruc: $data['ruc'] ?? $factura->ruc(),
            email: $data['email'] ?? $factura->email(),
            phone: $data['phone'] ?? $factura->phone(),
            address: $data['address'] ?? $factura->address(),
            city: $data['city'] ?? $factura->city(),
            country: $data['country'] ?? $factura->country(),
            status: $data['status'] ?? $factura->status(),
        );

        return $this->repository->save($factura);
    }
}
