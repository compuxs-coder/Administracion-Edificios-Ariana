<?php

namespace Src\Gastos\Application\Actions;

use Src\Edificio\Domain\Enums\PermisoEdificio;
use Src\Gastos\Domain\Contracts\DesembolsoRepositoryInterface;

final readonly class GetDesembolsoOptionsAction
{
    public function __construct(private DesembolsoRepositoryInterface $repository) {}

    /** @return array<string, mixed> */
    public function execute(string $userId, PermisoEdificio $permission = PermisoEdificio::DESEMBOLSOS_VER): array
    {
        return $this->repository->options($userId, $permission);
    }
}
