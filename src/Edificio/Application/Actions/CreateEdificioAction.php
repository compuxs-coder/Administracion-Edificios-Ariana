<?php

namespace Src\Edificio\Application\Actions;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Src\Edificio\Domain\Contracts\EdificioRepositoryInterface;
use Src\Edificio\Domain\Entities\Edificio;
use Src\Edificio\Domain\Enums\EstadoEdificio;

final readonly class CreateEdificioAction
{
    public function __construct(private EdificioRepositoryInterface $repository) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, string $userId): Edificio
    {
        $now = new DateTimeImmutable;
        $edificio = new Edificio(
            id: (string) Str::uuid(),
            nombre: $data['nombre'],
            ruc: $data['ruc'] ?? null,
            direccion: $data['direccion'],
            ciudad: $data['ciudad'],
            telefono: $data['telefono'] ?? null,
            correo: $data['correo'] ?? null,
            responsable: $data['responsable'] ?? null,
            estado: EstadoEdificio::ACTIVO,
            createdAt: $now,
            updatedAt: $now,
        );

        return $this->repository->createForUser($edificio, $userId);
    }
}
