<?php

namespace Src\Finanzas\Domain\Contracts;

use Src\Edificio\Domain\Enums\PermisoEdificio;

interface PagoRepositoryInterface
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function list(string $userId, array $filters): array;

    /** @return array<string, mixed> */
    public function get(string $userId, string $edificioId, string $pagoId): array;

    /** @return array<string, mixed> */
    public function options(string $userId, PermisoEdificio $permission = PermisoEdificio::FINANZAS_VER): array;

    /** @return array<string, mixed> */
    public function preview(string $userId, string $edificioId, string $departamentoId, string $valor, string $fechaPago): array;

    /** @param array<string, mixed> $data @return array{id: string} */
    public function create(string $userId, string $edificioId, array $data): array;

    /** @return array<string, mixed> */
    public function applyCredit(string $userId, string $edificioId, string $pagoId): array;

    public function cancel(string $userId, string $edificioId, string $pagoId, string $motivo): void;

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function cartera(string $userId, array $filters): array;
}
